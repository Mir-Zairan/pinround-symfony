<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Team;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\WaitingTurnCount;
use App\Insight\Api\WaitingTurnCounts;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Web\UI\Team\PersonWorkload;
use App\Web\UI\Team\TeamWorkloads;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TeamWorkloadsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000d0a01';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000d0c01';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000d0b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000d0b02';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000d0b03';

    /** Open tickets waiting on each person, by user id. */
    private const array WAITING = [self::LENA => 1, self::OMAR => 3, self::MIA => 0];

    #[Test]
    public function everyPersonOfTheTeamIsListedMostWaitingFirst(): void
    {
        $workload = $this->teamWorkloads(leftOutPersonId: null)->workloadOf(new TeamId(self::SHOP_TEAM), $this->viewer());

        self::assertSame(
            [['Omar Haddad', 3], ['Lena Berg', 1], ['Mia Lind', 0]],
            array_map(static fn (PersonWorkload $person): array => [$person->name, $person->waitingTickets], $workload->people),
        );
        self::assertSame(3, $workload->meterMax);
    }

    #[Test]
    public function aWaitingCountThatLeavesOutAPersonOfTheTeamFailsClosed(): void
    {
        $teamWorkloads = $this->teamWorkloads(leftOutPersonId: self::MIA);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The waiting counts leave out a person of the team.');

        $teamWorkloads->workloadOf(new TeamId(self::SHOP_TEAM), $this->viewer());
    }

    private function viewer(): ActorContext
    {
        return new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), new Roles(false, false, [new TeamId(self::SHOP_TEAM)], [], []));
    }

    private function teamWorkloads(?string $leftOutPersonId): TeamWorkloads
    {
        $teamPeople = self::createStub(TeamPeopleQueries::class);
        $teamPeople->method('namedPossibleAssigneesOf')->willReturn([
            new PossibleAssignee(new UserId(self::LENA), 'Lena', 'Lena Berg', MemberJob::Developer, true),
            new PossibleAssignee(new UserId(self::OMAR), 'Omar', 'Omar Haddad', MemberJob::Developer, false),
            new PossibleAssignee(new UserId(self::MIA), 'Mia', 'Mia Lind', MemberJob::Tester, false),
        ]);
        $ticketVisibilities = self::createStub(TicketVisibilities::class);
        $ticketVisibilities->method('of')->willReturn(
            TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [new TeamId(self::SHOP_TEAM)], false, null),
        );

        return new TeamWorkloads($teamPeople, $ticketVisibilities, self::waitingTurnCountsLeavingOut($leftOutPersonId));
    }

    /**
     * Answers like DbalWaitingTurnCounts (every asked person once, in the asked order, 0 when nothing waits on them),
     * except that it leaves out the one person given, as a broken count would.
     */
    private static function waitingTurnCountsLeavingOut(?string $leftOutPersonId): WaitingTurnCounts
    {
        return new readonly class(self::WAITING, $leftOutPersonId) implements WaitingTurnCounts {
            /**
             * @param array<string, int> $waitingByUserId
             */
            public function __construct(private array $waitingByUserId, private ?string $leftOutPersonId)
            {
            }

            public function waitingCountsOf(TicketViewer $viewer, array $userIds, ?TeamId $teamId, ?string $projectId): array
            {
                $counts = [];
                foreach ($userIds as $userId) {
                    $personId = $userId->toString();
                    if ($personId !== $this->leftOutPersonId) {
                        $counts[$personId] ??= new WaitingTurnCount($this->waitingByUserId[$personId] ?? 0, null);
                    }
                }

                return $counts;
            }
        };
    }
}
