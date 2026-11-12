<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Insight\Api\ProjectCountQueries;
use App\Insight\Api\ProjectCounts;
use App\Insight\Api\TurnProjector;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The project rail's counts (ProjectCountQueries, from ins_project_counts). They count by the ticket's team, not per
 * ticket: a viewer with a role in that team, or an admin, counts its team-only tickets; anyone else counts only
 * tickets for everyone, so the outside reporter Mia counts less than she sees, which is the documented undercount,
 * never a leak.
 */
final class ProjectCountsLeakTest extends VisibilityLeakCast
{
    /** What each viewer reads on the Shop team's rail: SHOP holds only the team-only ticket. */
    private const array SHOP_RAIL = ['Lena' => ['SHOP 1'], 'Mia' => ['SHOP 0'], 'Sara' => ['SHOP 1'], 'Omar' => ['SHOP 0'], 'Raj' => ['SHOP 0'], 'Noor' => ['SHOP 0'], 'Ben' => []];
    private const string PAST_PROJECT = '0197a3c0-0000-7000-8000-0000000057d3';
    private const string OPS_TEAM = '0197a3c0-0000-7000-8000-0000000057c2';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000057f1';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The cast restricts its ticket with a raw UPDATE, so it refreshes the ticket as every change must.
        self::transactions()->committedAs(self::ACME_FOODS, static fn () => self::turnProjector()->refresh(new TicketId(self::teamOnlyTicketId())));
        self::ensureKernelShutdown();
    }

    /**
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function railViewers(): iterable
    {
        foreach (self::viewers() as $case => [$viewer, $seesTeamOnly]) {
            yield $case => [$viewer, $seesTeamOnly, self::SHOP_RAIL[$viewer]];
        }
    }

    /**
     * @param list<string> $expectedRail
     */
    #[Test]
    #[DataProvider('railViewers')]
    public function theRailNeverCountsATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, array $expectedRail): void
    {
        self::assertTrue($seesTeamOnly || !\in_array('SHOP 1', $expectedRail, true), 'A viewer who may not see the team-only ticket must expect it uncounted.');

        self::assertSame($expectedRail, self::readRailAs($viewer, self::SHOP_TEAM));
    }

    /**
     * The tickets page's rail reads the counts of projects it already holds with the viewer's roles from the request
     * (countsOfProjects): each viewer's roles, as the cast gives them, count exactly what the team read counts. Ben, an
     * admin of another organization, asks for Shop's project by id and counts nothing.
     *
     * @param list<string> $expectedRail
     */
    #[Test]
    #[DataProvider('railViewers')]
    public function theRailReadOfGivenProjectsNeverCountsATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, array $expectedRail): void
    {
        $viewerRoles = ['Lena' => [false, [new TeamId(self::SHOP_TEAM)]], 'Sara' => [true, []], 'Ben' => [true, []]][$viewer] ?? [false, []];

        $rail = self::asViewer($viewer, static fn (): array => array_map(
            static fn (ProjectCounts $counts): string => 'SHOP '.$counts->openCount,
            self::projectCountQueries()->countsOfProjects([self::SHOP_PROJECT], $viewerRoles[0], $viewerRoles[1], false),
        ));

        self::assertSame([] === $expectedRail ? ['SHOP 0'] : $expectedRail, $rail);
        self::assertTrue($seesTeamOnly || 'SHOP 1' !== $rail[0], 'A viewer who may not see the team-only ticket must not count it.');
    }

    #[Test]
    public function theRailReadOfNoProjectRunsNoQuery(): void
    {
        $emptyRead = self::asViewer('Lena', static fn (): array => self::queriesRunBy(static fn (): array => self::projectCountQueries()->countsOfProjects([], false, [], true)));
        $oneProjectRead = self::asViewer('Lena', static fn (): array => self::queriesRunBy(static fn (): array => self::projectCountQueries()->countsOfProjects([self::SHOP_PROJECT], false, [], true)));

        self::assertSame([[], []], $emptyRead);
        self::assertCount(1, $oneProjectRead[1], 'The recorder sees the one read of a project.');
    }

    /**
     * Every id is checked before the read, so a valid one before the bad one runs no query either.
     */
    #[Test]
    public function theRailReadRefusesAnIdThatIsNotAUuidBeforeAnyQuery(): void
    {
        $refusedRead = self::asViewer('Lena', static fn (): array => self::queriesRunBy(static function (): string {
            try {
                self::projectCountQueries()->countsOfProjects([self::SHOP_PROJECT, "' OR true --"], true, [], true);
            } catch (\InvalidArgumentException $refusal) {
                return $refusal->getMessage();
            }

            return 'not refused';
        }));

        self::assertSame(['A project id is not a uuid.', []], $refusedRead);
    }

    /**
     * A project with only done team-only Shop tickets moves to a team Noor leads: the tickets keep their Shop team, so
     * Noor, in the new team, counts none of them, while the Shop lead and the admin still count them.
     */
    #[Test]
    public function aProjectMovedToAnotherTeamDoesNotCountItsTeamOnlyTicketsForTheNewTeam(): void
    {
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 7)",
            [self::ACME_FOODS, self::DONE_STEP],
        ));
        new TicketRows(self::migrator(), self::tenantContext())->addKeyedProjectOf(self::ACME_FOODS, self::PAST_PROJECT, self::SHOP_TEAM, 'PAST', 1);
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $ticketIds = [];
        foreach (['Old team-only fix', 'Another old team-only fix'] as $title) {
            $ticketIds[] = new TicketFactory($ticketCommands, self::transactions(), self::connection())->createOne(self::ACME_FOODS, self::PAST_PROJECT, self::VIEWERS['Lena'][0], ['title' => $title])->toString();
        }
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET step_id = ?, closed_at = '2027-03-16 10:00:00+00', visibility = 'team' WHERE id = ANY(CAST(? AS uuid[]))",
            [self::DONE_STEP, '{'.implode(',', $ticketIds).'}'],
        ));
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($ticketIds): void {
            foreach ($ticketIds as $ticketId) {
                self::turnProjector()->refresh(new TicketId($ticketId));
            }
        });
        $shopRailBeforeTheMove = self::readRailAs('Lena', self::SHOP_TEAM, withDone: true);

        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator): void {
            $migrator->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, 'Ops', 'ops', ?, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::OPS_TEAM, self::VIEWERS['Noor'][0]],
            );
            $migrator->executeStatement(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, 'lead', 'developer', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::OPS_TEAM, self::VIEWERS['Noor'][0]],
            );
            $migrator->executeStatement('UPDATE tkt_project SET team_id = ? WHERE id = ?', [self::OPS_TEAM, self::PAST_PROJECT]);
        });

        self::assertSame(['other 0/2', 'SHOP 1/0'], $shopRailBeforeTheMove);
        self::assertSame(['other 0/0'], self::readRailAs('Noor', self::OPS_TEAM, withDone: true));
        self::assertSame(['other 0/2'], self::readRailAs('Lena', self::OPS_TEAM, withDone: true));
        self::assertSame(['other 0/2'], self::readRailAs('Sara', self::OPS_TEAM, withDone: true));
    }

    /**
     * @return list<string> each of the team's projects as "SHOP <open>" or "other <open>", with "/<done>" when asked
     */
    private static function readRailAs(string $viewer, string $teamId, bool $withDone = false): array
    {
        return self::asViewer($viewer, static fn (): array => array_map(
            static fn (ProjectCounts $counts): string => (self::SHOP_PROJECT === $counts->projectId ? 'SHOP ' : 'other ').$counts->openCount.($withDone ? '/'.$counts->doneCount : ''),
            self::projectCountQueries()->countsOfTeam(new TeamId($teamId), new UserId(self::VIEWERS[$viewer][0]), $withDone),
        ));
    }

    /**
     * @return array{mixed, list<string>} what the work returned, and the SQL of every query it ran
     */
    private static function queriesRunBy(\Closure $work): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $returned = $work();
        $queries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                $queries[] = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        return [$returned, $queries];
    }

    private static function projectCountQueries(): ProjectCountQueries
    {
        $queries = self::getContainer()->get('test.insight_project_count_queries');
        \assert($queries instanceof ProjectCountQueries);

        return $queries;
    }

    private static function turnProjector(): TurnProjector
    {
        return self::getContainer()->get(TurnProjector::class);
    }
}
