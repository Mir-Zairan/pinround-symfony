<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The person picker's "N open" (AssigneeTicketCounts), read as the picker does: the viewer's visibility from
 * TicketVisibilities::of() under their own organization as tenant. Both cast tickets are assigned to Noor, so her count
 * is exactly the tickets the viewer may see; the no-team ticket's step is added as a To do step, so both are open.
 */
final class PersonPickerCountsLeakTest extends VisibilityLeakCast
{
    /** The step the cast's no-team ticket is in, which the cast itself does not add. */
    private const string NO_TEAM_TICKET_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::migrator()->transactional(static function (Connection $tenantMigrator): void {
                $tenantMigrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                    [self::ACME_FOODS, self::NO_TEAM_TICKET_STEP],
                );
                $tenantMigrator->executeStatement(
                    'UPDATE tkt_ticket SET assignee_id = ? WHERE id IN (?, ?)',
                    [self::VIEWERS['Noor'][0], self::teamOnlyTicketId(), self::NO_TEAM_TICKET],
                );
            });
        } finally {
            $tenantContext->clear();
        }
        self::ensureKernelShutdown();
    }

    /**
     * Noor sees both as their assignee, so she is left out: her count is not a check of the predicate.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function viewersOtherThanTheAssignee(): iterable
    {
        foreach (self::viewers() as $case => $viewer) {
            if ('Noor' !== $viewer[0]) {
                yield $case => $viewer;
            }
        }
    }

    #[Test]
    #[DataProvider('viewersOtherThanTheAssignee')]
    public function theCountNeverIncludesATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expectedCount = (int) $seesTeamOnly + (int) $seesNoTeamTicket;

        $counts = self::readCountsAs($viewer);

        self::assertSame([self::VIEWERS['Noor'][0] => $expectedCount, self::VIEWERS['Lena'][0] => 0], $counts, \sprintf('%s counts the wrong tickets.', $viewer));
    }

    /**
     * The expectations written out, so a change to the provider cannot hide one: an admin counts both, the triager
     * only the no-team one, the outside reporter only hers, an outsider and another organization's admin none.
     */
    #[Test]
    public function eachViewerCountsExactlyTheTicketsTheirRoleLetsThemSee(): void
    {
        $noor = self::VIEWERS['Noor'][0];

        self::assertSame(
            [2, 1, 1, 1, 0, 0],
            [self::readCountsAs('Sara')[$noor], self::readCountsAs('Omar')[$noor], self::readCountsAs('Mia')[$noor], self::readCountsAs('Lena')[$noor], self::readCountsAs('Raj')[$noor], self::readCountsAs('Ben')[$noor]],
        );
    }

    #[Test]
    public function noPersonIsCountedWithoutAQuery(): void
    {
        self::assertSame([], self::asViewer('Lena', static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo(
            self::ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::VIEWERS['Lena'][0])),
            [],
        )));
    }

    /**
     * @return array<string, int> Noor's and Lena's open counts, as the viewer reads them in a request
     */
    private static function readCountsAs(string $viewer): array
    {
        return self::asViewer($viewer, static function () use ($viewer): array {
            [$userId, $organizationId] = self::VIEWERS[$viewer];

            return self::assigneeTicketCounts()->openTicketsAssignedTo(
                self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId)),
                [new UserId(self::VIEWERS['Noor'][0]), new UserId(self::VIEWERS['Lena'][0])],
            );
        });
    }

    private static function assigneeTicketCounts(): AssigneeTicketCounts
    {
        $counts = self::getContainer()->get('test.ticketing_assignee_ticket_counts');
        \assert($counts instanceof AssigneeTicketCounts);

        return $counts;
    }

    private static function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }
}
