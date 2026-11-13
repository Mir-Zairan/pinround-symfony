<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Workflow\Application\Transition\GuardFixtures;
use App\Ticketing\UI\TicketPeople;
use App\Ticketing\UI\TicketPerson;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\LoadBasedTurnPeopleChoice;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The People section of a ticket of the Shop team of Acme Foods, with the real reviewer and tester choice: Lena leads
 * and develops, Omar develops, Mia tests; Raj, a manager outside the team, reports. Nothing here reads the clock.
 */
final class TicketPeopleTest extends TestCase
{
    /** Raj has no name here, as for a person whose user row is gone. */
    private const array NAMES = [
        GuardFixtures::LENA => 'Lena Shop',
        GuardFixtures::OMAR => 'Omar Shop',
        GuardFixtures::MIA => 'Mia Shop',
        GuardFixtures::SARA => 'Sara Shop',
    ];

    #[Test]
    public function aBacklogTicketListsItsReporterAndTheTeamsTesterAsTheRequirementAsks(): void
    {
        $people = self::peopleOf(GuardFixtures::ticket('backlog', 'todo', reporter: GuardFixtures::RAJ), self::turnOf(GuardFixtures::LENA));

        self::assertSame([
            ['An unknown person', 'Reporter', false],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function aToDoTicketListsItsAssigneeWithTheirJobButNoReviewer(): void
    {
        $people = self::peopleOf(GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR, reporter: GuardFixtures::RAJ), self::turnOf(GuardFixtures::OMAR));

        self::assertSame([
            ['Omar', 'Assignee · Developer · their turn', false],
            ['An unknown person', 'Reporter', false],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function anInProgressTicketListsTheReviewerTheMoveToCodeReviewWouldStore(): void
    {
        $people = self::peopleOf(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, reporter: GuardFixtures::RAJ), self::turnOf(GuardFixtures::OMAR));

        self::assertSame([
            ['Omar', 'Assignee · Developer · their turn', false],
            ['An unknown person', 'Reporter', false],
            ['Lena', 'Code reviewer · Lead', false],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function aCodeReviewTicketListsItsStoredReviewerOverTheTeamsChoice(): void
    {
        $team = new TeamFacts(
            new UserId(GuardFixtures::LENA),
            'Lena',
            [new TeamMemberLoad(new UserId(GuardFixtures::LENA)), new TeamMemberLoad(new UserId(GuardFixtures::OMAR)), new TeamMemberLoad(new UserId(GuardFixtures::SARA), openReviews: 4)],
            [new TeamMemberLoad(new UserId(GuardFixtures::MIA))],
        );
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::SARA, reporter: GuardFixtures::RAJ);

        $people = self::peopleOf($ticket, self::turnOf(GuardFixtures::SARA, NeedsYouKind::Review), $team);

        self::assertSame([
            ['Omar', 'Assignee · Developer', false],
            ['An unknown person', 'Reporter', false],
            ['Sara', 'Code reviewer · Developer · their turn', false],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function theLeadReviewsTheirOwnCodeWhenTheTeamHasNoOtherDeveloper(): void
    {
        $people = self::peopleOf(
            GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::LENA, reporter: GuardFixtures::LENA),
            self::turnOf(GuardFixtures::LENA),
            GuardFixtures::team(hasOtherDeveloper: false),
        );

        self::assertSame([
            ['Lena', 'Assignee · Code reviewer · Lead · their turn', false],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function aReporterWhoTestsIsListedOnceAsTheTesterWithTheTurnMarker(): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA, tester: GuardFixtures::MIA, reporter: GuardFixtures::MIA);

        $people = self::peopleOf($ticket, self::turnOf(GuardFixtures::MIA, NeedsYouKind::Test));

        self::assertSame([
            ['Omar', 'Assignee · Developer', false],
            ['Mia', 'Tester for the team · their turn', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function aDoneTicketListsNoTesterAndNoTurn(): void
    {
        $ticket = GuardFixtures::ticket('done', 'done', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA, tester: GuardFixtures::MIA, reporter: GuardFixtures::RAJ);

        $people = self::peopleOf($ticket, Turn::nobody());

        self::assertSame([
            ['Omar', 'Assignee · Developer', false],
            ['An unknown person', 'Reporter', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function aTeamWithoutATesterListsNoTester(): void
    {
        $people = self::peopleOf(GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR), self::turnOf(GuardFixtures::OMAR), GuardFixtures::team(testers: false));

        self::assertSame([['Omar', 'Assignee · Developer · their turn', false]], self::rowsOf($people));
    }

    #[Test]
    public function onlyTheViewersRowIsMarkedAsTheirs(): void
    {
        $people = self::peopleOf(GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR, reporter: GuardFixtures::LENA), self::turnOf(GuardFixtures::OMAR), viewerId: GuardFixtures::LENA);

        self::assertSame([
            ['Omar', 'Assignee · Developer · their turn', false],
            ['Lena', 'Reporter · Lead', true],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    #[Test]
    public function aRowCarriesTheFullNameForTheAvatarAndTheFirstNameForItsText(): void
    {
        $people = self::peopleOf(GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR), self::turnOf(GuardFixtures::OMAR), GuardFixtures::team(testers: false));

        self::assertSame(
            [[GuardFixtures::OMAR, 'Omar Shop', 'Omar']],
            array_map(static fn (TicketPerson $person): array => [$person->userId, $person->name, $person->firstName], $people),
        );
    }

    #[Test]
    public function aTicketNoTeamOwnsListsOnlyItsStoredPeopleWithoutJobs(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, tester: GuardFixtures::MIA, hasTeam: false, reporter: GuardFixtures::LENA);

        $people = new TicketPeople(self::turnPeopleChoice())->of($ticket, self::NAMES, null, null, new UserId(GuardFixtures::OMAR));

        self::assertSame([
            ['Omar', 'Assignee', true],
            ['Lena', 'Reporter', false],
            ['Mia', 'Tester for the team', false],
        ], self::rowsOf($people));
    }

    /**
     * @return list<TicketPerson>
     */
    private static function peopleOf(TicketState $ticket, Turn $turn, ?TeamFacts $team = null, string $viewerId = GuardFixtures::VIEWER): array
    {
        return new TicketPeople(self::turnPeopleChoice())->of($ticket, self::NAMES, $team ?? GuardFixtures::team(), $turn, new UserId($viewerId));
    }

    private static function turnPeopleChoice(): LoadBasedTurnPeopleChoice
    {
        return new LoadBasedTurnPeopleChoice(new ReviewerChoice(), new TesterChoice());
    }

    private static function turnOf(string $userId, NeedsYouKind $kind = NeedsYouKind::Build): Turn
    {
        return new Turn(new UserId($userId), null, $kind);
    }

    /**
     * The rows as the page reads them: first name, role line with the turn marker, and whether it is the viewer's.
     *
     * @param list<TicketPerson> $people
     *
     * @return list<array{string, string, bool}>
     */
    private static function rowsOf(array $people): array
    {
        return array_map(
            static fn (TicketPerson $person): array => [$person->firstName, $person->roleLine.($person->isTheirTurn ? ' · their turn' : ''), $person->isViewer],
            $people,
        );
    }
}
