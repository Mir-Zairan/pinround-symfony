<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Insight\Api\ProjectCountQueries;
use App\Insight\Api\ProjectCounts;
use App\Insight\Api\ProjectCountsRecount;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The project rail's counts (ProjectCountQueries, from ins_project_counts). They count by the team, not per ticket: a
 * viewer in the Shop team, or an admin, counts the team-only ticket; anyone else counts only tickets for everyone, so
 * its outside reporter Mia counts less than she sees, which is the documented undercount, never a leak.
 */
final class ProjectCountsLeakTest extends VisibilityLeakCast
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The cast restricts its ticket with a raw UPDATE after the creation counted it for everyone.
        self::transactions()->committedAs(self::ACME_FOODS, static fn () => self::getContainer()->get(ProjectCountsRecount::class)->recount(new OrganizationId(self::ACME_FOODS)));
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theRailNeverCountsATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $shopCounts = self::readRailAs($viewer);

        self::assertFalse(['SHOP 1'] === $shopCounts && !$seesTeamOnly, \sprintf('%s reads SHOP\'s rail count of %s with a ticket they may not see.', $viewer, json_encode($shopCounts)));
    }

    /**
     * The lead and the admin count the team-only ticket; Mia, its outside reporter, does not (the undercount); the
     * triager, the manager and an outsider count none; Beta Bakery's admin finds no Shop team, so no project.
     */
    #[Test]
    public function theRailCountsTeamOnlyTicketsOnlyForTheTeamAndAdmins(): void
    {
        self::assertSame(
            ['Lena' => ['SHOP 1'], 'Mia' => ['SHOP 0'], 'Sara' => ['SHOP 1'], 'Omar' => ['SHOP 0'], 'Raj' => ['SHOP 0'], 'Noor' => ['SHOP 0'], 'Ben' => []],
            array_map(self::readRailAs(...), array_combine(array_keys(self::VIEWERS), array_keys(self::VIEWERS))),
        );
    }

    /**
     * @return list<string> each Shop team project as "SHOP <open count>", or "other <open count>" for any other project
     */
    private static function readRailAs(string $viewer): array
    {
        return self::asViewer($viewer, static fn (): array => array_map(
            static fn (ProjectCounts $counts): string => (self::SHOP_PROJECT === $counts->projectId ? 'SHOP ' : 'other ').$counts->openCount,
            self::projectCountQueries()->countsOfTeam(new TeamId(self::SHOP_TEAM), new UserId(self::VIEWERS[$viewer][0]), false),
        ));
    }

    private static function projectCountQueries(): ProjectCountQueries
    {
        $queries = self::getContainer()->get('test.insight_project_count_queries');
        \assert($queries instanceof ProjectCountQueries);

        return $queries;
    }
}
