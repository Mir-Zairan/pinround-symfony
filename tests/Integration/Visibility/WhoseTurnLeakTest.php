<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\WaitingTicket;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Whose turn view's groups (HomeQueries::whoseTurn), read as a page would: the viewer's visibility from
 * TicketVisibilities::of() under their own organization as tenant. Noor is the turn of the cast's team-only SHOP ticket,
 * waiting since 1 March, and of a SHOP ticket for everyone, since 9 March, so a viewer who may not see the team-only one
 * still has her group. The cast's no-team ticket waits on the Customer label since 20 February, the oldest of Acme
 * Foods, and is that label's only ticket. Beta Bakery has a ticket waiting on Noor since 1 February.
 */
final class WhoseTurnLeakTest extends VisibilityLeakCast
{
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-00000000f7e1';
    private const string BETA_BAKERY_TICKET = '0197a3c0-0000-7000-8000-00000000f7e2';
    private const string TEAM_ONLY_SINCE = '2027-03-01 09:00:00';
    private const string NO_TEAM_SINCE = '2027-02-20 09:00:00';
    private const string FOR_EVERYONE_SINCE = '2027-03-09 09:00:00';
    private const string BETA_BAKERY_SINCE = '2027-02-01 09:00:00';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $noor = self::VIEWERS['Noor'][0];
        $ticketRows = new TicketRows(self::migrator(), self::tenantContext());
        $ticketRows->addOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_PROJECT, self::VIEWERS['Mia'][0], 70);
        $ticketRows->addOf(self::BETA_BAKERY, self::BETA_BAKERY_TICKET, self::SHOP_PROJECT, self::VIEWERS['Ben'][0], 71);
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($noor, $teamOnlyTicketId): void {
            $updated = $migrator->executeStatement(
                "UPDATE ins_ticket_turn SET turn_user_id = ?, turn_label = NULL, kind = 'build', grp = 'doing', waiting_since = ? WHERE ticket_id = ?",
                [$noor, self::TEAM_ONLY_SINCE.'+00', $teamOnlyTicketId],
            );
            if (1 !== $updated) {
                throw new \LogicException('The cast\'s team-only ticket has no turn row to wait on Noor.');
            }
            self::insertTurn($migrator, self::ACME_FOODS, self::NO_TEAM_TICKET, null, self::NO_TEAM_PROJECT, 'team', self::NO_TEAM_REPORTER, null, self::NO_TEAM_SINCE);
            self::insertTurn($migrator, self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_TEAM, self::SHOP_PROJECT, 'all', self::VIEWERS['Mia'][0], $noor, self::FOR_EVERYONE_SINCE);
        });
        self::asTenantMigrator(self::BETA_BAKERY, static fn (Connection $migrator) => self::insertTurn(
            $migrator,
            self::BETA_BAKERY,
            self::BETA_BAKERY_TICKET,
            null,
            self::SHOP_PROJECT,
            'all',
            self::VIEWERS['Ben'][0],
            $noor,
            self::BETA_BAKERY_SINCE,
        ));
        self::ensureKernelShutdown();
    }

    /**
     * Noor is the turn of both her tickets but none of their people, so she sees only the one for everyone, as her
     * role lets her. Ben reads another organization, so he is left out here.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function acmeFoodsViewers(): iterable
    {
        foreach (self::viewers() as $case => $viewer) {
            if ('Ben' !== $viewer[0]) {
                yield $case => $viewer;
            }
        }
    }

    #[Test]
    #[DataProvider('acmeFoodsViewers')]
    public function noCountOldestWaitOrRowIncludesATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $noorsGroup = [
            self::VIEWERS['Noor'][0],
            1 + (int) $seesTeamOnly,
            $seesTeamOnly ? self::TEAM_ONLY_SINCE : self::FOR_EVERYONE_SINCE,
            $seesTeamOnly ? [self::teamOnlyTicketId(), self::FOR_EVERYONE_TICKET] : [self::FOR_EVERYONE_TICKET],
        ];
        $customerGroup = ['Customer', 1, self::NO_TEAM_SINCE, [self::NO_TEAM_TICKET]];

        self::assertSame(
            $seesNoTeamTicket ? [$customerGroup, $noorsGroup] : [$noorsGroup],
            self::readGroupsAs($viewer),
            \sprintf('%s sees the wrong groups or tickets.', $viewer),
        );
    }

    /**
     * The expectations written out, so a change to the provider cannot hide one: an admin sees all three, the triager
     * the no-team one and the one for everyone, the lead and the outside reporter the team-only one and the one for
     * everyone, a manager only the one for everyone. Beta Bakery's ticket, the oldest, is in none of them.
     */
    #[Test]
    public function eachViewerSeesExactlyTheTicketsTheirRoleLetsThemSee(): void
    {
        $shownTicketsOf = static fn (string $viewer): array => array_merge(...array_map(static fn (array $group): array => $group[3], self::readGroupsAs($viewer)));

        self::assertSame(
            [
                'Sara' => [self::NO_TEAM_TICKET, self::teamOnlyTicketId(), self::FOR_EVERYONE_TICKET],
                'Omar' => [self::NO_TEAM_TICKET, self::FOR_EVERYONE_TICKET],
                'Mia' => [self::teamOnlyTicketId(), self::FOR_EVERYONE_TICKET],
                'Lena' => [self::teamOnlyTicketId(), self::FOR_EVERYONE_TICKET],
                'Raj' => [self::FOR_EVERYONE_TICKET],
            ],
            array_map($shownTicketsOf, ['Sara' => 'Sara', 'Omar' => 'Omar', 'Mia' => 'Mia', 'Lena' => 'Lena', 'Raj' => 'Raj']),
        );
    }

    #[Test]
    public function aViewerOutsideTheTeamNeverReadsTheTeamOnlyTicketsTitle(): void
    {
        $titles = self::asViewer('Raj', static fn (): array => array_merge(...array_map(
            static fn (TurnGroup $group): array => array_map(static fn (WaitingTicket $ticket): string => $ticket->title, $group->tickets),
            self::homeQueries()->whoseTurn(self::ticketViewerOf('Raj'), null, null),
        )));

        self::assertNotSame([], $titles);
        self::assertNotContains(self::LEAK_MARKER, $titles);
    }

    #[Test]
    public function anotherOrganizationsViewerSeesOnlyTheirOwnOrganizationsTickets(): void
    {
        self::assertSame(
            [[self::VIEWERS['Noor'][0], 1, self::BETA_BAKERY_SINCE, [self::BETA_BAKERY_TICKET]]],
            self::readGroupsAs('Ben'),
        );
    }

    private static function insertTurn(Connection $migrator, string $organizationId, string $ticketId, ?string $teamId, string $projectId, string $audience, string $reporterId, ?string $turnUserId, string $waitingSince): void
    {
        $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, ?, ?, 'doing', ?, ?, 'build', 100, ?, ?, ?)",
            [$organizationId, $ticketId, $teamId, $projectId, $turnUserId, null === $turnUserId ? 'Customer' : null, $waitingSince.'+00', $audience, $reporterId],
        );
    }

    /**
     * @return list<array{string, int, string, list<string>}> each group's key, ticket count, oldest wait in UTC and shown
     *                                                        ticket ids, as the viewer reads them in a request
     */
    private static function readGroupsAs(string $viewer): array
    {
        $groups = self::asViewer($viewer, static fn (): array => self::homeQueries()->whoseTurn(self::ticketViewerOf($viewer), null, null));

        return array_map(static fn (TurnGroup $group): array => [
            $group->groupKey(),
            $group->ticketCount,
            $group->oldestWaitingSince->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            array_map(static fn (WaitingTicket $ticket): string => $ticket->id->toString(), $group->tickets),
        ], $groups);
    }

    private static function ticketViewerOf(string $viewer): TicketViewer
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];
        $visibility = self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));

        return new TicketViewer($visibility->organizationId(), $visibility->viewerId(), $visibility->teamIds(), $visibility->isAdmin(), $visibility->isTriager());
    }

    private static function homeQueries(): HomeQueries
    {
        $queries = self::getContainer()->get('test.insight_home_queries');
        \assert($queries instanceof HomeQueries);

        return $queries;
    }

    private static function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }
}
