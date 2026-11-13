<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\WaitingTurnCount;
use App\Insight\Api\WaitingTurnCounts;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The waiting counts of the "Give it to…" picker and the Team page (WaitingTurnCounts), read as a page would: the
 * viewer's visibility from TicketVisibilities::of() under their own organization as tenant. Noor is the assignee and turn
 * of three Acme Foods tickets: the cast's team-only SHOP ticket, waiting since 1 March; the cast's no-team ticket, given
 * by hand the turn row it would have, since 20 February; and a SHOP ticket for everyone, since 9 March, so a viewer who
 * may see neither restricted one still counts one. Beta Bakery has a ticket waiting on her since 1 February, the oldest.
 */
final class WaitingTurnCountsLeakTest extends VisibilityLeakCast
{
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-00000000f0e1';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000f0e2';
    private const string TEAM_ONLY_SINCE = '2027-03-01 09:00:00';
    private const string NO_TEAM_SINCE = '2027-02-20 09:00:00';
    private const string FOR_EVERYONE_SINCE = '2027-03-09 09:00:00';
    private const string BETA_BAKERY_SINCE = '2027-02-01 09:00:00';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $noor = self::VIEWERS['Noor'][0];
        $ticketRows = new TicketRows(self::migrator(), self::tenantContext());
        $ticketRows->addOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_PROJECT, self::VIEWERS['Mia'][0], 60);
        $ticketRows->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::VIEWERS['Ben'][0], 61);
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($noor, $teamOnlyTicketId): void {
            $migrator->executeStatement('UPDATE tkt_ticket SET assignee_id = ? WHERE id IN (?, ?)', [$noor, $teamOnlyTicketId, self::NO_TEAM_TICKET]);
            $updated = $migrator->executeStatement(
                "UPDATE ins_ticket_turn SET assignee_id = ?, turn_user_id = ?, turn_label = NULL, kind = 'build', grp = 'doing', waiting_since = ? WHERE ticket_id = ?",
                [$noor, $noor, self::TEAM_ONLY_SINCE.'+00', $teamOnlyTicketId],
            );
            if (1 !== $updated) {
                throw new \LogicException('The cast\'s team-only ticket has no turn row to wait on Noor.');
            }
            self::insertTurn($migrator, self::ACME_FOODS, self::NO_TEAM_TICKET, null, self::NO_TEAM_PROJECT, 'team', self::NO_TEAM_REPORTER, self::NO_TEAM_SINCE);
            self::insertTurn($migrator, self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_TEAM, self::SHOP_PROJECT, 'all', self::VIEWERS['Mia'][0], self::FOR_EVERYONE_SINCE);
        });
        self::asTenantMigrator(self::BETA_BAKERY, static fn (Connection $migrator) => self::insertTurn(
            $migrator,
            self::BETA_BAKERY,
            self::BETA_BAKERY_TICKET,
            null,
            self::SHOP_PROJECT,
            'all',
            self::VIEWERS['Ben'][0],
            self::BETA_BAKERY_SINCE,
        ));
        self::ensureKernelShutdown();
    }

    /**
     * Noor sees all three as their assignee and Ben counts Beta Bakery's ticket, so both are left out: their counts are
     * no check of the predicate.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function acmeFoodsViewersOtherThanTheAssignee(): iterable
    {
        foreach (self::viewers() as $case => $viewer) {
            if (!\in_array($viewer[0], ['Noor', 'Ben'], true)) {
                yield $case => $viewer;
            }
        }
    }

    #[Test]
    #[DataProvider('acmeFoodsViewersOtherThanTheAssignee')]
    public function neitherTheCountNorTheOldestIncludesATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expectedOldest = $seesNoTeamTicket ? self::NO_TEAM_SINCE : ($seesTeamOnly ? self::TEAM_ONLY_SINCE : self::FOR_EVERYONE_SINCE);

        self::assertSame(
            [self::VIEWERS['Noor'][0] => [1 + (int) $seesTeamOnly + (int) $seesNoTeamTicket, $expectedOldest], self::VIEWERS['Lena'][0] => [0, null]],
            self::readCountsAs($viewer),
            \sprintf('%s counts the wrong tickets.', $viewer),
        );
    }

    /**
     * The expectations written out, so a change to the provider cannot hide one: an admin counts all three, the
     * triager the no-team one and the one for everyone, the lead and the outside reporter the team-only one and the
     * one for everyone, a manager only the one for everyone. Beta Bakery's ticket, the oldest, counts for none of them.
     */
    #[Test]
    public function eachViewerCountsExactlyTheTicketsTheirRoleLetsThemSee(): void
    {
        $noor = self::VIEWERS['Noor'][0];

        self::assertSame(
            [
                'Sara' => [3, self::NO_TEAM_SINCE],
                'Omar' => [2, self::NO_TEAM_SINCE],
                'Mia' => [2, self::TEAM_ONLY_SINCE],
                'Lena' => [2, self::TEAM_ONLY_SINCE],
                'Raj' => [1, self::FOR_EVERYONE_SINCE],
            ],
            array_map(static fn (string $viewer): array => self::readCountsAs($viewer)[$noor], ['Sara' => 'Sara', 'Omar' => 'Omar', 'Mia' => 'Mia', 'Lena' => 'Lena', 'Raj' => 'Raj']),
        );
    }

    /**
     * Ben, Beta Bakery's admin, counts only his organization's ticket; none of Acme Foods' three.
     */
    #[Test]
    public function anotherOrganizationsViewerCountsOnlyTheirOwnOrganizationsTickets(): void
    {
        self::assertSame([1, self::BETA_BAKERY_SINCE], self::readCountsAs('Ben')[self::VIEWERS['Noor'][0]]);
    }

    /**
     * The Team page filters by the team: the lead counts the team's two tickets, never the no-team one; the manager only
     * the one for everyone.
     */
    #[Test]
    public function aTeamsCountHoldsOnlyTheTicketsOfThatTeamTheViewerMaySee(): void
    {
        $noor = self::VIEWERS['Noor'][0];

        self::assertSame(
            [[2, self::TEAM_ONLY_SINCE], [2, self::TEAM_ONLY_SINCE], [1, self::FOR_EVERYONE_SINCE]],
            [self::readCountsAs('Sara', self::SHOP_TEAM)[$noor], self::readCountsAs('Lena', self::SHOP_TEAM)[$noor], self::readCountsAs('Raj', self::SHOP_TEAM)[$noor]],
        );
    }

    private static function insertTurn(Connection $migrator, string $organizationId, string $ticketId, ?string $teamId, string $projectId, string $audience, string $reporterId, string $waitingSince): void
    {
        $noor = self::VIEWERS['Noor'][0];
        $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             VALUES (?, ?, ?, ?, 'doing', ?, 'build', 100, ?, ?, ?, ?)",
            [$organizationId, $ticketId, $teamId, $projectId, $noor, $waitingSince.'+00', $audience, $reporterId, $noor],
        );
    }

    /**
     * @return array<string, array{int, string|null}> Noor's and Lena's counts and oldest waits in UTC, as the viewer reads them in a request
     */
    private static function readCountsAs(string $viewer, ?string $teamId = null): array
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];

        $counts = self::asViewer($viewer, static function () use ($userId, $organizationId, $teamId): array {
            $visibility = self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));

            return self::waitingTurnCounts()->waitingCountsOf(
                new TicketViewer($visibility->organizationId(), $visibility->viewerId(), $visibility->teamIds(), $visibility->isAdmin(), $visibility->isTriager()),
                [new UserId(self::VIEWERS['Noor'][0]), new UserId(self::VIEWERS['Lena'][0])],
                null === $teamId ? null : new TeamId($teamId),
                null,
            );
        });

        return array_map(
            static fn (WaitingTurnCount $count): array => [$count->tickets, $count->oldestWaitingSince?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            $counts,
        );
    }

    private static function waitingTurnCounts(): WaitingTurnCounts
    {
        $counts = self::getContainer()->get('test.insight_waiting_turn_counts');
        \assert($counts instanceof WaitingTurnCounts);

        return $counts;
    }

    private static function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }
}
