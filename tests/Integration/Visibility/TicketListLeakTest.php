<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tickets list, read as a page would: the viewer's visibility from RoleTicketVisibilities::of() under their own
 * organization as tenant, every open group, and each step's count beside its rows.
 */
final class TicketListLeakTest extends VisibilityLeakCast
{
    /** The step the cast's no-team ticket is in, which the cast itself does not add. */
    private const string NO_TEAM_TICKET_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';

    private static ?string $teamOnlyTicketStepId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::$teamOnlyTicketStepId = $migrator->transactional(static function (Connection $tenantMigrator): string {
                $tenantMigrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                    [self::ACME_FOODS, self::NO_TEAM_TICKET_STEP],
                );
                $stepId = $tenantMigrator->fetchOne('SELECT step_id FROM tkt_ticket WHERE id = ?', [self::teamOnlyTicketId()]);
                \assert(\is_string($stepId));

                return $stepId;
            });
        } finally {
            $tenantContext->clear();
        }
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$teamOnlyTicketStepId = null;
        parent::tearDownAfterClass();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theListShowsATeamOnlyTicketOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expectedTicketIds = array_keys(array_filter([self::teamOnlyTicketId() => $seesTeamOnly, self::NO_TEAM_TICKET => $seesNoTeamTicket]));

        [$listedTicketIds, $stepCounts] = self::readListAs($viewer, null);

        self::assertEqualsCanonicalizing($expectedTicketIds, $listedTicketIds, \sprintf('%s lists the wrong tickets.', $viewer));
        self::assertSame(\count($expectedTicketIds), array_sum($stepCounts), \sprintf('%s\'s step counts include a ticket they may not see.', $viewer));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theListOfShopShowsItsTeamOnlyTicketOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        // The no-team ticket is in OPS, so whoever may see it never finds it in SHOP's list.
        $expectedTicketIds = $seesTeamOnly ? [self::teamOnlyTicketId()] : [];

        [$listedTicketIds, $stepCounts] = self::readListAs($viewer, self::SHOP_PROJECT);

        self::assertSame([$expectedTicketIds, \count($expectedTicketIds)], [$listedTicketIds, array_sum($stepCounts)], \sprintf('%s lists the wrong SHOP tickets.', $viewer));
    }

    /**
     * A cursor before every ticket of the team-only ticket's step (the lowest rank, a time after every ticket, the
     * highest id) asks for that step's next page; the visibility filter runs there as on the first page.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function aCursorPageShowsTheTeamOnlyTicketOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $stepId = self::$teamOnlyTicketStepId ?? throw new \LogicException('The step is read before each class.');
        $cursor = new Cursor($stepId, 0, new \DateTimeImmutable('2999-01-01 00:00:00 UTC'), new TicketId('ffffffff-ffff-7fff-bfff-ffffffffffff'));

        $firstPage = self::readStepAs($viewer, $stepId, null);
        $cursorPage = self::readStepAs($viewer, $stepId, $cursor);

        self::assertSame($seesTeamOnly, \in_array(self::teamOnlyTicketId(), $firstPage[0], true), \sprintf('%s\'s first page is wrong.', $viewer));
        self::assertSame($firstPage, $cursorPage, \sprintf('%s\'s cursor page differs from their first page.', $viewer));
    }

    /**
     * The visibility the cast's viewers() expects, written out by viewer so a change to the provider cannot hide one:
     * Beta Bakery's admin lists no SHOP row, the outsider and the manager with no team role no team-only row, the
     * outside reporter their team-only ticket, and the triager the team-only ticket with no team.
     */
    #[Test]
    public function eachViewerListsExactlyTheTicketsTheirRoleLetsThemSee(): void
    {
        self::assertSame([[], []], self::readListAs('Ben', null));
        self::assertSame([[], []], self::readListAs('Noor', null));
        self::assertSame([[], []], self::readListAs('Raj', null));
        self::assertSame([[self::teamOnlyTicketId()], [1]], self::readListAs('Mia', null));
        self::assertSame([[self::NO_TEAM_TICKET], [1]], self::readListAs('Omar', null));
    }

    /**
     * What the tickets list shows the viewer in a request: the ids of its rows and each listed step's count, in list
     * order.
     *
     * @return array{list<string>, list<int>}
     */
    private static function readListAs(string $viewer, ?string $projectId): array
    {
        return self::asViewer($viewer, static function () use ($viewer, $projectId): array {
            [$userId, $organizationId] = self::VIEWERS[$viewer];
            $visibility = self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));
            $listedTicketIds = [];
            $stepCounts = [];
            foreach (self::ticketQueries()->list(new ListFilter($visibility, $projectId, true)) as $group) {
                foreach ($group->steps as $step) {
                    $stepCounts[] = $step->ticketCount;
                    foreach ($step->rows as $row) {
                        $listedTicketIds[] = $row->id->toString();
                    }
                }
            }

            return [$listedTicketIds, $stepCounts];
        });
    }

    /**
     * The ids of the rows the viewer is shown on one step and that step's count, empty when the step is not listed.
     *
     * @return array{list<string>, list<int>}
     */
    private static function readStepAs(string $viewer, string $stepId, ?Cursor $cursor): array
    {
        return self::asViewer($viewer, static function () use ($viewer, $stepId, $cursor): array {
            [$userId, $organizationId] = self::VIEWERS[$viewer];
            $visibility = self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));
            $listedTicketIds = [];
            $stepCounts = [];
            foreach (self::ticketQueries()->list(new ListFilter($visibility, null, true), $cursor) as $group) {
                foreach ($group->steps as $step) {
                    if ($step->stepId !== $stepId) {
                        continue;
                    }
                    $stepCounts[] = $step->ticketCount;
                    foreach ($step->rows as $row) {
                        $listedTicketIds[] = $row->id->toString();
                    }
                }
            }

            return [$listedTicketIds, $stepCounts];
        });
    }

    private static function ticketQueries(): TicketQueries
    {
        $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
        \assert($ticketQueries instanceof TicketQueries);

        return $ticketQueries;
    }

    private static function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }
}
