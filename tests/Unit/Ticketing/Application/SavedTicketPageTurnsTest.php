<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Insight\Api\SavedTurn;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPagePerson;
use App\Ticketing\Api\TicketPageTurnView;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketRole;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\SavedTicketPageTurns;
use App\Ticketing\Application\TicketPeopleRoles;
use App\Workflow\Application\Turn\LoadBasedTurnPeopleChoice;
use App\Workflow\Application\Turn\OrderedTurnRules;
use App\Workflow\Application\Turn\PlainNowNext;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A ticket's Now and Next lines and People section, as the ticket page and get_ticket read them, with Workflow's real
 * turn rules, people choice and sentences. SHOP-7 of Acme Foods' Shop team: Lena leads (and reviews, unless she wrote
 * the code), Omar and Sara develop, Mia tests; Omar is the assignee and Raj, a manager outside the team, the reporter.
 * Nothing here reads the clock.
 */
final class SavedTicketPageTurnsTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    private const string MIA = '0190a000-0000-7000-8000-00000000000c';
    private const string RAJ = '0190a000-0000-7000-8000-00000000000d';
    private const string SARA = '0190a000-0000-7000-8000-00000000000e';
    private const string TICKET = '0190a000-0000-7000-8000-0000000000e1';
    private const string SHOP_TEAM = '0190a000-0000-7000-8000-0000000000f2';
    private const string STEP = '0190a000-0000-7000-8000-0000000000f3';
    private const string PROJECT = '0190a000-0000-7000-8000-0000000000f5';
    private const string ACME_FOODS = '0190a000-0000-7000-8000-0000000000f6';

    /** The names the page holds: the ticket's people and its team. */
    private const array PAGE_NAMES = [
        self::LENA => 'Lena Shop',
        self::OMAR => 'Omar Shop',
        self::MIA => 'Mia Shop',
        self::RAJ => 'Raj Shop',
        self::SARA => 'Sara Shop',
    ];

    private FixedTurnReads $reads;

    protected function setUp(): void
    {
        $this->reads = new FixedTurnReads();
        $this->reads->knownNames = self::PAGE_NAMES;
    }

    #[Test]
    public function theSavedTurnOfAPausedTicketNamesWhoAnswersWhoseQuestion(): void
    {
        $this->reads->savedTurn = new SavedTurn(new UserId(self::OMAR), null, 'answer');

        $view = $this->viewOf(self::snapshot(asked: self::OMAR, asker: self::LENA));

        self::assertSame(['Omar answers Lena’s question', 'it goes back to In progress'], [$view->now, $view->next]);
        self::assertSame(1, $this->reads->loadReads);
        self::assertSame([], $this->reads->namesAskedFor, 'Every person named is on the page, so no name is looked up.');
    }

    #[Test]
    public function theSavedTurnWinsOverTheTurnTheRulesWouldChoose(): void
    {
        $this->reads->savedTurn = new SavedTurn(new UserId(self::SARA), null, 'build');

        $view = $this->viewOf(self::snapshot());

        self::assertSame('Sara builds it', $view->now);
    }

    #[Test]
    public function aTicketWithNoSavedTurnGetsTheTurnFromTheRules(): void
    {
        $view = $this->viewOf(self::snapshot());

        self::assertSame(['Omar builds it', 'Lena reviews the code, then Mia tests it'], [$view->now, $view->next]);
        self::assertSame(1, $this->reads->loadReads);
    }

    #[Test]
    public function anAskerThePageDoesNotHoldIsLookedUpWithTheOtherPeopleInOneQuery(): void
    {
        $outsider = '0190a000-0000-7000-8000-00000000001c';
        $this->reads->knownNames[$outsider] = 'Ola Outside';
        $this->reads->savedTurn = new SavedTurn(new UserId(self::OMAR), null, 'answer');

        $view = $this->viewOf(self::snapshot(asked: self::OMAR, asker: $outsider), [new UserId($outsider), new UserId(self::MIA)]);

        self::assertSame('Omar answers Ola’s question', $view->now);
        self::assertSame([[$outsider]], $this->reads->namesAskedFor);
        self::assertSame('Ola Outside', $view->displayNames[$outsider]);
    }

    #[Test]
    public function anInProgressTicketListsTheLeadAsReviewerAndTheTeamsTester(): void
    {
        $view = $this->viewOf(self::snapshot());

        self::assertSame([
            [self::OMAR, ['assignee']],
            [self::RAJ, ['reporter']],
            [self::LENA, ['reviewer']],
            [self::MIA, ['tester']],
        ], self::peopleOf($view));
    }

    #[Test]
    public function aTicketTheLeadBuildsListsTheDeveloperTheTeamWouldPickToReview(): void
    {
        $view = $this->viewOf(self::snapshot('review', 'check', developers: [self::LENA, self::OMAR, self::SARA], assignee: self::LENA));

        self::assertSame([
            [self::LENA, ['assignee']],
            [self::RAJ, ['reporter']],
            [self::OMAR, ['reviewer']],
            [self::MIA, ['tester']],
        ], self::peopleOf($view));
    }

    #[Test]
    public function aCodeReviewTicketListsItsStoredReviewer(): void
    {
        $view = $this->viewOf(self::snapshot('review', 'check', reviewer: self::LENA));

        self::assertSame([
            [self::OMAR, ['assignee']],
            [self::RAJ, ['reporter']],
            [self::LENA, ['reviewer']],
            [self::MIA, ['tester']],
        ], self::peopleOf($view));
    }

    #[Test]
    public function theLeadReviewsTheirOwnCodeWhenTheTeamHasNoOtherDeveloper(): void
    {
        $view = $this->viewOf(self::snapshot('review', 'check', developers: [self::LENA], assignee: self::LENA));

        self::assertSame([
            [self::LENA, ['assignee', 'reviewer']],
            [self::RAJ, ['reporter']],
            [self::MIA, ['tester']],
        ], self::peopleOf($view));
    }

    #[Test]
    public function aTestingTicketListsNoReviewer(): void
    {
        $view = $this->viewOf(self::snapshot('test', 'check', reviewer: self::SARA, tester: self::MIA));

        self::assertSame([
            [self::OMAR, ['assignee']],
            [self::RAJ, ['reporter']],
            [self::MIA, ['tester']],
        ], self::peopleOf($view));
    }

    #[Test]
    public function aDoneTicketListsNoTester(): void
    {
        $view = $this->viewOf(self::snapshot('done', 'done', reviewer: self::SARA, tester: self::MIA));

        self::assertSame([
            [self::OMAR, ['assignee']],
            [self::RAJ, ['reporter']],
        ], self::peopleOf($view));
        self::assertNull($view->next);
    }

    #[Test]
    public function aReporterWhoTestsIsOnePersonWithBothRoles(): void
    {
        $view = $this->viewOf(self::snapshot(tester: self::MIA, reporter: self::MIA));

        self::assertSame([
            [self::OMAR, ['assignee']],
            [self::MIA, ['reporter', 'tester']],
            [self::LENA, ['reviewer']],
        ], self::peopleOf($view));
    }

    #[Test]
    public function aTicketNoTeamOwnsHasNoLinesAndListsOnlyItsStoredPeople(): void
    {
        $view = $this->viewOf(self::snapshot(null, 'doing', hasTeam: false));

        self::assertNull($view->now);
        self::assertNull($view->next);
        self::assertSame([
            [self::OMAR, ['assignee']],
            [self::RAJ, ['reporter']],
        ], self::peopleOf($view));
        self::assertSame(0, $this->reads->loadReads, 'No turn is read for a ticket no team owns.');
        self::assertSame(self::PAGE_NAMES, $view->displayNames);
    }

    /**
     * @param list<UserId> $otherPeopleIds
     */
    private function viewOf(TicketTurnSnapshot $snapshot, array $otherPeopleIds = []): TicketPageTurnView
    {
        $turns = new SavedTicketPageTurns(
            $this->reads,
            $this->reads,
            $this->reads,
            new OrderedTurnRules(new ReviewerChoice(), new TesterChoice()),
            new PlainNowNext(new ReviewerChoice(), new TesterChoice()),
            new TicketPeopleRoles(new LoadBasedTurnPeopleChoice(new ReviewerChoice(), new TesterChoice())),
        );

        return $turns->of(self::page($snapshot), $otherPeopleIds);
    }

    /**
     * @param list<string> $developers
     */
    private static function snapshot(
        ?string $coreCode = 'progress',
        string $groupCode = 'doing',
        bool $hasTeam = true,
        array $developers = [self::OMAR, self::SARA],
        ?string $reviewer = null,
        ?string $tester = null,
        string $reporter = self::RAJ,
        ?string $asked = null,
        ?string $asker = null,
        string $assignee = self::OMAR,
    ): TicketTurnSnapshot {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            self::PROJECT,
            self::STEP,
            $coreCode,
            $groupCode,
            $hasTeam ? new TeamId(self::SHOP_TEAM) : null,
            $hasTeam ? new UserId(self::LENA) : null,
            $hasTeam ? 'Lena Shop' : null,
            $hasTeam ? array_map(static fn (string $userId): UserId => new UserId($userId), $developers) : [],
            $hasTeam ? [new UserId(self::MIA)] : [],
            TicketVisibilityFacts::FOR_EVERYONE,
            'major',
            null,
            new UserId($reporter),
            assigneeId: new UserId($assignee),
            reviewerId: null === $reviewer ? null : new UserId($reviewer),
            testerId: null === $tester ? null : new UserId($tester),
            askedUserId: null === $asked ? null : new UserId($asked),
            askerId: null === $asker ? null : new UserId($asker),
        );
    }

    private static function page(TicketTurnSnapshot $snapshot): TicketPageView
    {
        return new TicketPageView(
            $snapshot->ticketId,
            'SHOP-7',
            'Cart total is wrong',
            '',
            'bug',
            'major',
            null,
            null,
            [],
            new \DateTimeImmutable('2026-11-04 10:00:00 UTC'),
            'Shop',
            'In progress',
            $snapshot->groupCode,
            'Doing',
            'Raj Shop',
            'Omar Shop',
            TicketVisibilityFacts::ofSnapshot(new OrganizationId(self::ACME_FOODS), $snapshot),
            $snapshot,
            self::PAGE_NAMES,
            3,
        );
    }

    /**
     * @return list<array{string, list<string>}> each person's user id and role codes
     */
    private static function peopleOf(TicketPageTurnView $view): array
    {
        return array_map(
            static fn (TicketPagePerson $person): array => [$person->userId->toString(), array_map(static fn (TicketRole $role): string => $role->value, $person->roles)],
            $view->people,
        );
    }
}
