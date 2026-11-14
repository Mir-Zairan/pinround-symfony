<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Component;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\WaitingTurnCount;
use App\Insight\Api\WaitingTurnCounts;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\Roles;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Web\UI\Component\PersonPickerMode;
use App\Web\UI\Component\PersonPickerSearch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The picker's order and counts per mode. The team is listed out of every order the picker could use, Ana and Lena tie
 * on both counts and Mia and Tom on the waiting count, so a sort that drops a key or flips its direction shows.
 */
final class PersonPickerSearchTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c0a01';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000c0c01';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c0d01';
    private const string VIEWER = '0197a3c0-0000-7000-8000-0000000c0b01';

    /** Each person's id, first name, open tickets assigned to them and open tickets waiting on them. */
    private const array PEOPLE = [
        'Omar' => ['0197a3c0-0000-7000-8000-0000000c0b02', 3, 3],
        'Tom' => ['0197a3c0-0000-7000-8000-0000000c0b03', 0, 0],
        'Lena' => ['0197a3c0-0000-7000-8000-0000000c0b04', 1, 1],
        'Mia' => ['0197a3c0-0000-7000-8000-0000000c0b05', 2, 0],
        'Ana' => ['0197a3c0-0000-7000-8000-0000000c0b06', 1, 1],
    ];

    /** @var list<array{TicketViewer, list<string>, ?string, ?string}> */
    private array $waitingCountCalls = [];

    /** @var list<string> the people the waiting counts leave out, as a broken count would */
    private array $leftOutOfWaitingCounts = [];

    private bool $wereOpenCountsRead = false;

    #[Test]
    public function theAssignModeListsTheFewestWaitingFirstThenByNameAndCountsOnlyTheTeamsTickets(): void
    {
        $picker = $this->openedPicker(PersonPickerMode::Assign);

        self::assertSame(
            [['Mia', 0], ['Tom', 0], ['Ana', 1], ['Lena', 1], ['Omar', 3]],
            self::namesAndCountsOf($picker->shownPeople()),
        );
        self::assertCount(1, $this->waitingCountCalls, 'One count read for the whole team.');
        [$viewer, $askedIds, $teamId, $projectId] = $this->waitingCountCalls[0];
        self::assertSame(
            [self::ACME_FOODS, self::VIEWER, [self::SHOP_TEAM], false, false, self::SHOP_TEAM, null],
            [$viewer->organizationId->toString(), $viewer->viewerId->toString(), array_map(static fn (TeamId $id): string => $id->toString(), $viewer->teamIds), $viewer->isAdmin, $viewer->isTriager, $teamId, $projectId],
        );
        self::assertSame(array_column(self::PEOPLE, 0), $askedIds);
        self::assertFalse($this->wereOpenCountsRead, 'The assign mode does not count assigned tickets.');
    }

    #[Test]
    public function theAssignModeCountsInTheProjectTheListShows(): void
    {
        $picker = $this->openedPicker(PersonPickerMode::Assign);
        $picker->projectId = self::SHOP_PROJECT;

        $picker->shownPeople();

        self::assertSame(self::SHOP_PROJECT, $this->waitingCountCalls[0][3]);
    }

    #[Test]
    public function theFilterModeListsTheMostOpenFirstThenByName(): void
    {
        $picker = $this->openedPicker(PersonPickerMode::Filter);

        self::assertSame(
            [['Omar', 3], ['Mia', 2], ['Ana', 1], ['Lena', 1], ['Tom', 0]],
            self::namesAndCountsOf($picker->shownPeople()),
        );
        self::assertSame([], $this->waitingCountCalls, 'The filter mode does not count waiting tickets.');
    }

    #[Test]
    public function aWaitingCountThatLeavesOutAPersonFailsClosed(): void
    {
        $this->leftOutOfWaitingCounts = [self::PEOPLE['Lena'][0]];
        $picker = $this->openedPicker(PersonPickerMode::Assign);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The waiting counts leave out a person of the team.');

        $picker->shownPeople();
    }

    /**
     * @param array{rows: list<array{personId: string, name: string, count: int, isPressed: bool}>, matchCount: int} $shownPeople
     *
     * @return list<array{string, int}>
     */
    private static function namesAndCountsOf(array $shownPeople): array
    {
        self::assertNotSame([], $shownPeople['rows']);

        return array_map(static fn (array $row): array => [$row['name'], $row['count']], $shownPeople['rows']);
    }

    private function openedPicker(PersonPickerMode $mode): PersonPickerSearch
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser(self::VIEWER, null), 'main'));
        $securityServices = new Container();
        $securityServices->set('security.token_storage', $tokenStorage);
        $organizationId = new OrganizationId(self::ACME_FOODS);
        $viewerId = new UserId(self::VIEWER);
        $shopTeam = new TeamId(self::SHOP_TEAM);

        $teamPeople = self::createStub(TeamPeopleQueries::class);
        $teamPeople->method('namedPossibleAssigneesOf')->willReturn(array_map(
            static fn (string $name, array $person): PossibleAssignee => new PossibleAssignee(new UserId($person[0]), $name, $name, MemberJob::Developer, 'Lena' === $name),
            array_keys(self::PEOPLE),
            self::PEOPLE,
        ));
        $ticketVisibilities = self::createStub(TicketVisibilities::class);
        $ticketVisibilities->method('of')->willReturn(TicketVisibility::for($organizationId, $viewerId, [$shopTeam], false, null));
        $assigneeTicketCounts = self::createStub(AssigneeTicketCounts::class);
        $assigneeTicketCounts->method('openTicketsAssignedTo')->willReturnCallback(function (): array {
            $this->wereOpenCountsRead = true;

            return array_combine(array_column(self::PEOPLE, 0), array_column(self::PEOPLE, 1));
        });
        $waitingTurnCounts = self::createStub(WaitingTurnCounts::class);
        $waitingTurnCounts->method('waitingCountsOf')->willReturnCallback(function (TicketViewer $viewer, array $userIds, ?TeamId $teamId, ?string $projectId): array {
            $askedIds = [];
            foreach ($userIds as $userId) {
                \assert($userId instanceof UserId);
                $askedIds[] = $userId->toString();
            }
            $this->waitingCountCalls[] = [$viewer, $askedIds, $teamId?->toString(), $projectId];
            $waitingById = array_combine(array_column(self::PEOPLE, 0), array_column(self::PEOPLE, 2));
            $counts = [];
            foreach (array_diff($askedIds, $this->leftOutOfWaitingCounts) as $askedId) {
                $counts[$askedId] = new WaitingTurnCount($waitingById[$askedId] ?? 0, null);
            }

            return $counts;
        });

        $picker = new PersonPickerSearch(
            new Security($securityServices),
            new TenantContext(new class implements TenantSwitchGuard {
                public function prepareSwitch(): void
                {
                }
            }),
            new FixedCurrentActor([self::VIEWER => new ActorContext($viewerId, $organizationId, new Roles(false, false, [], [$shopTeam], []))]),
            $teamPeople,
            $ticketVisibilities,
            $assigneeTicketCounts,
            $waitingTurnCounts,
        );
        $picker->mode = $mode;
        $picker->team = self::SHOP_TEAM;
        $picker->open();

        return $picker;
    }
}
