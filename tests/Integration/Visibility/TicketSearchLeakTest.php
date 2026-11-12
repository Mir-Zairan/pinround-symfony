<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tickets list with a search, read as a page would: search_ticket_ids picks the matches and the list joins them
 * back to tkt_ticket under the viewer's visibility, so each viewer lists only the matches they may see.
 *
 * Beta Bakery has a step and a ticket of its own about the checkout, numbered past every cast ticket, so its admin
 * lists something and a search that crossed organizations would show.
 */
final class TicketSearchLeakTest extends VisibilityLeakCast
{
    /** Finds the cast's team-only ticket by its title, the no-team ticket and Beta Bakery's by theirs. */
    private const string QUERY = 'LEAK-MARKER or checkout';
    /** The step the cast's no-team ticket is in, which the cast itself does not add. */
    private const string NO_TEAM_TICKET_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string BETA_STEP = '0197a3c0-0000-7000-8000-0000000058f1';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000058d4';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000058e4';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        new TicketRows($migrator, $tenantContext)->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::VIEWERS['Ben'][0], 77);
        // TicketRows puts every ticket in the no-team ticket's step; Beta Bakery's moves to a step of its own.
        foreach ([self::ACME_FOODS => [self::NO_TEAM_TICKET_STEP, null], self::BETA_BAKERY => [self::BETA_STEP, self::BETA_TICKET]] as $organizationId => [$stepId, $movedTicketId]) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                $migrator->transactional(static function (Connection $tenantMigrator) use ($organizationId, $stepId, $movedTicketId): void {
                    $tenantMigrator->executeStatement(
                        "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                        [$organizationId, $stepId],
                    );
                    if (null !== $movedTicketId) {
                        $tenantMigrator->executeStatement('UPDATE tkt_ticket SET step_id = ? WHERE id = ?', [$stepId, $movedTicketId]);
                    }
                });
            } finally {
                $tenantContext->clear();
            }
        }
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function aSearchListsATeamOnlyTicketOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expectedTicketIds = 'Ben' === $viewer
            ? [self::BETA_TICKET]
            : array_keys(array_filter([self::teamOnlyTicketId() => $seesTeamOnly, self::NO_TEAM_TICKET => $seesNoTeamTicket]));

        [$listedTicketIds, $stepCounts] = self::searchListAs($viewer, self::QUERY);

        self::assertEqualsCanonicalizing($expectedTicketIds, $listedTicketIds, \sprintf('%s lists the wrong matches.', $viewer));
        self::assertSame(\count($expectedTicketIds), array_sum($stepCounts), \sprintf('%s\'s step counts include a match they may not see.', $viewer));
    }

    /**
     * The exact key is the strongest match the search has; it still lists the ticket only to a viewer who may see it,
     * and never to Beta Bakery's admin, whose own ticket has another key.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function aSearchForTheTeamOnlyTicketsKeyListsItOnlyToAViewerWhoMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $key = self::asViewer('Lena', static fn (): mixed => self::connection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [self::teamOnlyTicketId()]));
        self::assertIsString($key);
        self::assertNotSame('SHOP-77', $key);

        [$listedTicketIds, $stepCounts] = self::searchListAs($viewer, strtolower($key));

        self::assertSame($seesTeamOnly ? [[self::teamOnlyTicketId()], [1]] : [[], []], [$listedTicketIds, $stepCounts], $viewer);
    }

    /**
     * The visibility the cast's viewers() expects, written out by viewer so a change to the provider cannot hide one:
     * the outsider and the manager with no team role list no team-only match, the outside reporter their team-only
     * ticket, the triager the team-only ticket with no team, and Beta Bakery's admin only Beta Bakery's ticket.
     */
    #[Test]
    public function eachViewerListsExactlyTheMatchesTheirRoleLetsThemSee(): void
    {
        self::assertSame([[], []], self::searchListAs('Noor', self::QUERY));
        self::assertSame([[], []], self::searchListAs('Raj', self::QUERY));
        self::assertSame([[self::teamOnlyTicketId()], [1]], self::searchListAs('Mia', self::QUERY));
        self::assertSame([[self::NO_TEAM_TICKET], [1]], self::searchListAs('Omar', self::QUERY));
        self::assertSame([[self::BETA_TICKET], [1]], self::searchListAs('Ben', self::QUERY));
        self::assertSame([[], []], self::searchListAs('Ben', 'LEAK-MARKER'));
    }

    /**
     * What the tickets list shows the viewer for the search in a request: the ids of its rows and each listed step's
     * count, in list order, Done included.
     *
     * @return array{list<string>, list<int>}
     */
    private static function searchListAs(string $viewer, string $searchText): array
    {
        return self::asViewer($viewer, static function () use ($viewer, $searchText): array {
            [$userId, $organizationId] = self::VIEWERS[$viewer];
            $visibility = self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));
            $listedTicketIds = [];
            $stepCounts = [];
            foreach (self::ticketQueries()->list(new ListFilter($visibility, null, true, $searchText)) as $group) {
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
