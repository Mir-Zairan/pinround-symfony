<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TurnProjector;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Ticketing\Api\Event\TicketMoved;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\WaitingOnRefused;
use App\Ticketing\Application\MoveTicket\MoveTicket;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketHistory;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use App\Ticketing\Infrastructure\Messenger\MessengerTicketEvents;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Availability;
use App\Workflow\Api\Decision;
use App\Workflow\Api\MoveChoices;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnPeopleChoice;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * TicketCommands::move and moveTo on the real tables, policy, outbox and turn projector, as pinround_app under
 * row-level security. Acme Foods' ticket SHOP-7 starts in To do, reported by Mia and assigned to Omar; Beta Bakery has
 * a SHOP-7 and a SHOP-8 of its own, and Acme a second team with a step of its own, which no check may reach.
 */
final class MoveTicketTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e711';
    private const string WAREHOUSE_TEAM = '0197a3c0-0000-7000-8000-00000000e712';
    private const string BETA_TEAM = '0197a3c0-0000-7000-8000-00000000e713';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e721';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000e722';
    private const string IN_PROGRESS_STEP = '0197a3c0-0000-7000-8000-00000000e741';
    private const string CODE_REVIEW_STEP = '0197a3c0-0000-7000-8000-00000000e742';
    private const string TESTING_STEP = '0197a3c0-0000-7000-8000-00000000e743';
    private const string NEEDS_INPUT_STEP = '0197a3c0-0000-7000-8000-00000000e744';
    private const string ON_HOLD_STEP = '0197a3c0-0000-7000-8000-00000000e745';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-00000000e746';
    private const string PAIRING_STEP = '0197a3c0-0000-7000-8000-00000000e747';
    private const string WAREHOUSE_STEP = '0197a3c0-0000-7000-8000-00000000e748';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e753';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000e754';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000e755';
    private const string KEY = 'SHOP-7';
    private const string MOVED_AT = '2027-03-16 09:30:00+00:00';
    private const string NO_TEAM_ROLE_NOTE = 'Managers can read and comment. Only the team moves tickets.';
    private const string TICKET_BY_KEY = 'SELECT t0.project_id AS project_id_1, t0.number AS number_2, t0.key AS key_3,';
    private const string WAITING_TURNS = 'SELECT turn_user_id, kind, count(*) AS tickets FROM ins_ticket_turn';
    private const string SAVED = 'UPDATE tkt_ticket SET step_id = ?, previous_step_id = ?,';
    // The turn projector's writes, the history row, then the event bus's doctrine_transaction savepoint around the
    // outbox's own.
    private const array PROJECTED_AND_PUBLISHED = [
        'INSERT INTO ins_project_count_ticket ',
        'INSERT INTO ins_ticket_turn ',
        'INSERT INTO tkt_ticket_event ',
        'SAVEPOINT DOCTRINE_2',
        'SAVEPOINT DOCTRINE_3',
        'INSERT INTO messenger_outbox ',
        'SELECT pg_notify(?, ?)',
        'RELEASE SAVEPOINT DOCTRINE_3',
        'RELEASE SAVEPOINT DOCTRINE_2',
    ];

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'viewer', 'other'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::BETA_TEAM, self::BETA_PROJECT, self::SARA, [self::SARA => ['Sara', 'lead', 'developer']]);
        $this->addAcmeSteps();
        $commands = $this->ticketCommands();
        $shopTickets = new ShopTickets($commands, $this->transactions(), $this->connection());
        $shopTickets->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $shopTickets->createdFor(self::BETA_BAKERY, self::BETA_PROJECT, self::SARA, self::SARA);
        $shopTickets->createdFor(self::BETA_BAKERY, self::BETA_PROJECT, self::SARA, self::SARA);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string}>
     */
    public static function verbMoves(): iterable
    {
        // The verb, who clicks it, the ticket's columns before, and the step it must end in.
        yield 'plan_it' => ['plan_it', self::LENA, ['step_id' => ShopTeamRows::BACKLOG_STEP], ShopTeamRows::TO_DO_STEP];
        yield 'start_work' => ['start_work', self::OMAR, [], self::IN_PROGRESS_STEP];
        yield 'send_to_code_review' => ['send_to_code_review', self::OMAR, ['step_id' => self::IN_PROGRESS_STEP], self::CODE_REVIEW_STEP];
        yield 'approve_code' => ['approve_code', self::LENA, ['step_id' => self::CODE_REVIEW_STEP, 'reviewer_id' => self::LENA], self::TESTING_STEP];
        yield 'ask_for_changes' => ['ask_for_changes', self::LENA, ['step_id' => self::CODE_REVIEW_STEP, 'reviewer_id' => self::LENA], self::IN_PROGRESS_STEP];
        yield 'pass_it' => ['pass_it', self::MIA, ['step_id' => self::TESTING_STEP, 'tester_id' => self::MIA], self::DONE_STEP];
        yield 'needs_changes' => ['needs_changes', self::MIA, ['step_id' => self::TESTING_STEP, 'tester_id' => self::MIA], self::IN_PROGRESS_STEP];
        yield 'input_given back to the previous step' => ['input_given', self::LENA, ['step_id' => self::NEEDS_INPUT_STEP, 'previous_step_id' => self::CODE_REVIEW_STEP, 'waiting_on_user_id' => self::MIA], self::CODE_REVIEW_STEP];
        yield 'go_on back to the previous step' => ['go_on', self::LENA, ['step_id' => self::ON_HOLD_STEP, 'previous_step_id' => self::TESTING_STEP], self::TESTING_STEP];
        yield 'reopen' => ['reopen', self::LENA, ['step_id' => self::DONE_STEP, 'closed_at' => '2027-03-15 12:00:00+00'], self::IN_PROGRESS_STEP];
        yield 'put_on_hold' => ['put_on_hold', self::LENA, [], self::ON_HOLD_STEP];
        yield 'move_on from a team step' => ['move_on', self::OMAR, ['step_id' => self::PAIRING_STEP], self::CODE_REVIEW_STEP];
    }

    /**
     * @param array<string, string> $columnsBefore
     */
    #[Test]
    #[DataProvider('verbMoves')]
    public function aVerbMovesTheTicketAndWritesItsHistoryRowTurnRowAndEvent(string $verb, string $actor, array $columnsBefore, string $expectedStepId): void
    {
        $this->setColumns($columnsBefore);
        [$stepBefore, $versionBefore] = $this->stepAndVersion();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId($actor),
            self::KEY,
            $verb,
            $versionBefore,
            new \DateTimeImmutable(self::MOVED_AT),
        ));

        $this->assertMoved($stepBefore, $versionBefore, $expectedStepId, $actor, $verb);
        $this->assertBetaTicketsUntouched();
    }

    /**
     * Omar and Mia are both developers with no review of their own code, so the lead's ticket goes to the one with fewer
     * waiting reviews; with equal loads Omar would win on the lower user id.
     */
    #[Test]
    public function theLeadsTicketSentToCodeReviewGoesToTheDeveloperWithFewerWaitingReviews(): void
    {
        $this->makeMiaADeveloperWhileOmarReviewsAnotherTicket();
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP, 'assignee_id' => self::LENA]);
        $ticketId = $this->ticketId();

        $this->move(self::LENA, 'send_to_code_review', $this->stepAndVersion()[1]);

        self::assertSame([self::MIA, null], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::MIA, 'kind' => 'review'], $this->turnOf($ticketId));
        $turnRowsAfterTheMove = $this->turnRows();
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));
        self::assertSame($this->turnRows(), $turnRowsAfterTheMove);
    }

    /**
     * FR-WF-14: Mia gets the review on the loads at the move. Once Omar's other review leaves Code review the loads tie
     * and a fresh choice would name Omar on the lower user id, so only the stored reviewer keeps the turn and the verb
     * with Mia.
     */
    #[Test]
    public function theReviewerChosenAtTheMoveKeepsTheReviewAfterTheLoadsChange(): void
    {
        $omarsReviewId = $this->makeMiaADeveloperWhileOmarReviewsAnotherTicket();
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP, 'assignee_id' => self::LENA]);
        $ticketId = $this->ticketId();
        $this->move(self::LENA, 'send_to_code_review', $this->stepAndVersion()[1]);
        $this->moveOutOfItsCheckStep($omarsReviewId);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->move(self::OMAR, 'approve_code', $stateBefore['version']));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertNull($refusal->ruleNoteTemplate);
        self::assertSame([], $refusal->ruleNotePeople);
        self::assertSame($stateBefore, $this->storedState());
        self::assertSame([self::MIA, null], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::MIA, 'kind' => 'review'], $this->turnOf($ticketId));

        $this->move(self::MIA, 'approve_code', $stateBefore['version']);

        self::assertSame([self::TESTING_STEP, $stateBefore['version'] + 1], $this->stepAndVersion());
        self::assertSame([self::MIA, null], $this->storedReviewerAndTester());
    }

    /**
     * A reviewer stored before, here by the lead, is kept when the ticket comes back to Code review, though the loads
     * at that move would now choose Mia.
     */
    #[Test]
    public function aStoredReviewerIsKeptWhenTheTicketReturnsToCodeReview(): void
    {
        $this->makeMiaADeveloperWhileOmarReviewsAnotherTicket();
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP, 'assignee_id' => self::LENA, 'reviewer_id' => self::OMAR]);

        $this->move(self::LENA, 'send_to_code_review', $this->stepAndVersion()[1]);

        self::assertSame([self::OMAR, null], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::OMAR, 'kind' => 'review'], $this->turnOf($this->ticketId()));
    }

    /**
     * The lead reviewing their own code is a fallback, so nobody is stored and the history keeps the override note.
     */
    #[Test]
    public function theLeadReviewingTheirOwnCodeIsNotStored(): void
    {
        self::mockTime(self::MOVED_AT);
        $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $connection): int|string => $connection->executeStatement(
            "UPDATE org_team_member SET team_role = 'viewer', job = 'other' WHERE organization_id = ? AND team_id = ? AND user_id = ?",
            [self::ACME_FOODS, self::SHOP_TEAM, self::OMAR],
        ));
        self::getContainer()->get(TeamPeopleCache::class)->invalidateTeamNow(new TeamId(self::SHOP_TEAM));
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP, 'assignee_id' => self::LENA]);

        $this->move(self::LENA, 'send_to_code_review', $this->stepAndVersion()[1]);

        self::assertSame([null, null], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::LENA, 'kind' => 'review'], $this->turnOf($this->ticketId()));
        self::assertSame(
            ['Lena reviews their own code: no other developer in the team'],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn("SELECT payload->>'override_note' FROM tkt_ticket_event WHERE type = 'moved'")),
        );
    }

    /**
     * With equal loads Mia would test it on the lower user id, so only the waiting tests the move read send it to Raj.
     */
    #[Test]
    public function approvedCodeGoesToTheTesterWithFewerWaitingTests(): void
    {
        $this->makeRajASecondTesterWhileMiaTestsAnotherTicket();
        $this->setColumns(['step_id' => self::CODE_REVIEW_STEP, 'reviewer_id' => self::LENA]);
        $ticketId = $this->ticketId();

        $this->move(self::LENA, 'approve_code', $this->stepAndVersion()[1]);

        self::assertSame([self::LENA, self::RAJ], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::RAJ, 'kind' => 'test'], $this->turnOf($ticketId));
        $turnRowsAfterTheMove = $this->turnRows();
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));
        self::assertSame($this->turnRows(), $turnRowsAfterTheMove);
    }

    /**
     * FR-WF-14: Raj gets the test on the loads at the move. Once Mia's other test leaves Testing the loads tie and a
     * fresh choice would name Mia on the lower user id, so only the stored tester keeps the turn with Raj.
     */
    #[Test]
    public function theTesterChosenAtTheMoveKeepsTheTestAfterTheLoadsChange(): void
    {
        $miasTestId = $this->makeRajASecondTesterWhileMiaTestsAnotherTicket();
        $this->setColumns(['step_id' => self::CODE_REVIEW_STEP, 'reviewer_id' => self::LENA]);
        $ticketId = $this->ticketId();
        $this->move(self::LENA, 'approve_code', $this->stepAndVersion()[1]);

        $this->moveOutOfItsCheckStep($miasTestId);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));

        self::assertSame([self::LENA, self::RAJ], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::RAJ, 'kind' => 'test'], $this->turnOf($ticketId));
    }

    /**
     * With Mia a developer the team has no tester, so nobody is stored and the Testing turn is the lead's.
     */
    #[Test]
    public function approvedCodeInATeamWithoutATesterStoresNoTester(): void
    {
        $this->makeMiaADeveloperWhileOmarReviewsAnotherTicket();
        $this->setColumns(['step_id' => self::CODE_REVIEW_STEP, 'reviewer_id' => self::LENA]);

        $this->move(self::LENA, 'approve_code', $this->stepAndVersion()[1]);

        self::assertSame([self::TESTING_STEP, null], [$this->stepAndVersion()[0], $this->storedReviewerAndTester()[1]]);
        self::assertSame(['turn_user_id' => self::LENA, 'kind' => 'test'], $this->turnOf($this->ticketId()));
    }

    /**
     * A ticket in Testing with no tester stored is the less busy tester's turn; with equal loads it would be Mia's. Any
     * tester may pass it, so the waiting tests decide only the turn, which Done then clears.
     */
    #[Test]
    public function theLessBusyTesterPassesATicketWithNoTesterStoredAndTheTurnsMatchAFreshRefresh(): void
    {
        $this->makeRajASecondTesterWhileMiaTestsAnotherTicket();
        $this->setColumns(['step_id' => self::TESTING_STEP]);
        $ticketId = $this->ticketId();
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));
        self::assertSame(
            ['turn_user_id' => self::RAJ, 'kind' => 'test'],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative('SELECT turn_user_id, kind FROM ins_ticket_turn WHERE ticket_id = ?', [$ticketId->toString()])),
        );
        $versionBefore = $this->stepAndVersion()[1];

        $this->move(self::RAJ, 'pass_it', $versionBefore);

        self::assertSame([self::DONE_STEP, $versionBefore + 1], $this->stepAndVersion());
        $turnRowsAfterTheMove = $this->turnRows();
        self::assertSame([], array_values(array_filter($turnRowsAfterTheMove, static fn (array $turnRow): bool => $turnRow['ticket_id'] === $ticketId->toString())));
        self::assertCount(1, $turnRowsAfterTheMove);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));
        self::assertSame($this->turnRows(), $turnRowsAfterTheMove);
    }

    #[Test]
    public function sendToTestingMovesTheTicketWhenTheTeamLetsTestingComeBeforeReview(): void
    {
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP]);
        $this->addRuleSet(reviewBeforeTest: false);
        [$stepBefore, $versionBefore] = $this->stepAndVersion();

        $this->move(self::OMAR, 'send_to_testing', $versionBefore);

        $this->assertMoved($stepBefore, $versionBefore, self::TESTING_STEP, self::OMAR, 'send_to_testing');
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string|null, array<string, string>}>
     */
    public static function refusedVerbMoves(): iterable
    {
        // The verb, who clicks it, the ticket's columns before, and the rule note template and people it is refused with.
        yield 'review before test, a rule with a note' => ['send_to_testing', self::OMAR, ['step_id' => self::IN_PROGRESS_STEP], 'Team rule: code review comes before testing.', []];
        yield 'no self review, a note naming the reviewer' => ['approve_code', self::OMAR, ['step_id' => self::CODE_REVIEW_STEP, 'reviewer_id' => self::LENA], 'Team rule: nobody approves their own code. {reviewer} reviews it.', ['reviewer' => self::LENA]];
        yield 'a member using a lead-only verb' => ['put_on_hold', self::OMAR, [], null, []];
        yield 'a viewer, who has no team role' => ['start_work', self::RAJ, [], self::NO_TEAM_ROLE_NOTE, []];
        yield 'a person of another organization' => ['start_work', self::SARA, [], self::NO_TEAM_ROLE_NOTE, []];
        yield 'a verb that does not apply in the step' => ['pass_it', self::LENA, [], null, []];
        yield 'an unknown verb' => ['jump', self::LENA, [], null, []];
        yield 'give_it_to, which comes with P03.T8' => ['give_it_to', self::LENA, [], null, []];
    }

    /**
     * @param array<string, string> $columnsBefore
     * @param array<string, string> $expectedPeople
     */
    #[Test]
    #[DataProvider('refusedVerbMoves')]
    public function aRefusedVerbSaysWhyAndChangesNothing(string $verb, string $actor, array $columnsBefore, ?string $expectedTemplate, array $expectedPeople): void
    {
        $this->setColumns($columnsBefore);
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->move($actor, $verb, $stateBefore['version']));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertSame($expectedTemplate, $refusal->ruleNoteTemplate);
        self::assertSame($expectedPeople, array_map(static fn (UserId $userId): string => $userId->toString(), $refusal->ruleNotePeople));
        self::assertSame($stateBefore, $this->storedState());
    }

    #[Test]
    public function aTicketNoTeamOwnsIsNotMoved(): void
    {
        $this->setColumns(['team_id' => null]);
        $stateBefore = $this->storedState();

        $verbRefusal = $this->refusalOf(fn () => $this->move(self::OMAR, 'start_work', $stateBefore['version']));
        $moveToRefusal = $this->refusalOf(fn () => $this->moveTo(self::LENA, self::IN_PROGRESS_STEP, $stateBefore['version']));

        self::assertInstanceOf(MoveRefused::class, $verbRefusal);
        self::assertNull($verbRefusal->ruleNoteTemplate);
        self::assertSame([], $verbRefusal->ruleNotePeople);
        self::assertInstanceOf(MoveRefused::class, $moveToRefusal);
        self::assertNull($moveToRefusal->ruleNoteTemplate);
        self::assertSame([], $moveToRefusal->ruleNotePeople);
        self::assertSame($stateBefore, $this->storedState());
    }

    /**
     * @return iterable<string, array{string|null, string|null, string|null, string|null}>
     */
    public static function needsInputMoves(): iterable
    {
        // Who is named, by person or label, and who the ticket must wait on.
        yield 'a person' => [self::RAJ, null, self::RAJ, null];
        yield 'a label' => [null, '  Customer  ', null, 'Customer'];
        yield 'nobody, so the reporter' => [null, null, self::MIA, null];
        yield 'a label of 100 multibyte characters' => [null, str_repeat('é', 100), null, str_repeat('é', 100)];
    }

    #[Test]
    #[DataProvider('needsInputMoves')]
    public function theLeadMovesTheTicketToNeedsInputWaitingOnWhoIsNamedOrItsReporter(?string $waitingOnUserId, ?string $waitingOnLabel, ?string $expectedUserId, ?string $expectedLabel): void
    {
        [$stepBefore, $versionBefore] = $this->stepAndVersion();

        $this->moveTo(self::LENA, self::NEEDS_INPUT_STEP, $versionBefore, null === $waitingOnUserId ? null : new UserId($waitingOnUserId), $waitingOnLabel);

        $this->assertMoved($stepBefore, $versionBefore, self::NEEDS_INPUT_STEP, self::LENA, null);
        self::assertSame(
            ['waiting_on_user_id' => $expectedUserId, 'waiting_on_label' => $expectedLabel],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative('SELECT waiting_on_user_id, waiting_on_label FROM tkt_ticket WHERE key = ?', [self::KEY])),
        );
    }

    #[Test]
    public function theLeadMovesTheTicketToATeamStepWithoutAVerb(): void
    {
        [$stepBefore, $versionBefore] = $this->stepAndVersion();

        $this->moveTo(self::LENA, strtoupper(self::PAIRING_STEP), $versionBefore);

        $this->assertMoved($stepBefore, $versionBefore, self::PAIRING_STEP, self::LENA, null);
    }

    #[Test]
    public function theLeadMovesTheTicketFromInProgressToTestingWhenTheTeamLetsTestingComeBeforeReview(): void
    {
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP]);
        $this->addRuleSet(reviewBeforeTest: false);
        [$stepBefore, $versionBefore] = $this->stepAndVersion();

        $this->moveTo(self::LENA, self::TESTING_STEP, $versionBefore);

        $this->assertMoved($stepBefore, $versionBefore, self::TESTING_STEP, self::LENA, null);
        self::assertSame([null, self::MIA], $this->storedReviewerAndTester());
    }

    /**
     * A move without a verb stores the reviewer as a verb does, from the waiting reviews it read: Omar would win a tie.
     */
    #[Test]
    public function theLeadMovingTheirTicketToCodeReviewStoresTheDeveloperWithFewerWaitingReviews(): void
    {
        $this->makeMiaADeveloperWhileOmarReviewsAnotherTicket();
        $this->setColumns(['step_id' => self::IN_PROGRESS_STEP, 'assignee_id' => self::LENA]);

        $this->moveTo(self::LENA, self::CODE_REVIEW_STEP, $this->stepAndVersion()[1]);

        self::assertSame([self::MIA, null], $this->storedReviewerAndTester());
        self::assertSame(['turn_user_id' => self::MIA, 'kind' => 'review'], $this->turnOf($this->ticketId()));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string|null, string|null, string|null, array<string, string>}>
     */
    public static function refusedMovesTo(): iterable
    {
        // Who moves, to which step, the ticket's columns before, who is named to wait on, and the rule note template and
        // people it is refused with.
        yield 'a member' => [self::OMAR, self::IN_PROGRESS_STEP, [], null, null, null, []];
        yield 'a viewer' => [self::RAJ, self::IN_PROGRESS_STEP, [], null, null, self::NO_TEAM_ROLE_NOTE, []];
        yield 'review before test, for the lead too' => [self::LENA, self::TESTING_STEP, ['step_id' => self::IN_PROGRESS_STEP], null, null, 'Team rule: code review comes before testing.', []];
        yield 'the step it is in' => [self::LENA, ShopTeamRows::TO_DO_STEP, [], null, null, null, []];
        yield 'another team\'s step' => [self::LENA, self::WAREHOUSE_STEP, [], null, null, null, []];
        yield 'a step no organization has' => [self::LENA, '0197a3c0-0000-7000-8000-00000000e749', [], null, null, null, []];
        yield 'a person to wait on outside Needs input' => [self::LENA, self::IN_PROGRESS_STEP, [], self::RAJ, null, null, []];
        yield 'a label to wait on outside Needs input' => [self::LENA, self::IN_PROGRESS_STEP, [], null, 'Customer', null, []];
        yield 'a person of another organization to wait on' => [self::LENA, self::NEEDS_INPUT_STEP, [], self::SARA, null, null, []];
    }

    #[Test]
    public function aPersonWhoLeftTheOrganizationIsRefusedToWaitOn(): void
    {
        $this->removeMembershipOf(self::RAJ);
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->moveTo(self::LENA, self::NEEDS_INPUT_STEP, $stateBefore['version'], new UserId(self::RAJ)));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertNull($refusal->ruleNoteTemplate);
        self::assertSame([], $refusal->ruleNotePeople);
        self::assertSame($stateBefore, $this->storedState());
    }

    #[Test]
    public function aReporterWhoLeftTheOrganizationIsRefusedToWaitOnWhenNobodyIsNamed(): void
    {
        $this->removeMembershipOf(self::MIA);
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->moveTo(self::LENA, self::NEEDS_INPUT_STEP, $stateBefore['version']));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertNull($refusal->ruleNoteTemplate);
        self::assertSame([], $refusal->ruleNotePeople);
        self::assertSame($stateBefore, $this->storedState());
        self::assertSame([null, null], $this->waitingOn());
    }

    /**
     * No verb of the catalogue leads to Needs input yet, so a policy that allows one stands in for the verb that will.
     */
    #[Test]
    public function aVerbMoveToNeedsInputWaitsOnTheReporterOnlyWhileTheyAreAMember(): void
    {
        $moveTicket = $this->moveTicketWith(new DoctrineTickets($this->entityManager()), $this->policyAllowingEveryVerbToNeedsInput());
        $verbMove = fn (int $expectedVersion) => $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $moveTicket->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::LENA),
            self::KEY,
            'ask_for_input',
            $expectedVersion,
            new \DateTimeImmutable(self::MOVED_AT),
        ));
        $this->removeMembershipOf(self::MIA);
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(static fn () => $verbMove($stateBefore['version']));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertNull($refusal->ruleNoteTemplate);
        self::assertSame($stateBefore, $this->storedState());
        self::assertSame([null, null], $this->waitingOn());

        $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $connection): int|string => $connection->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
            [self::ACME_FOODS, self::MIA],
        ));

        $verbMove($stateBefore['version']);

        self::assertSame([self::NEEDS_INPUT_STEP, $stateBefore['version'] + 1], $this->stepAndVersion());
        self::assertSame([self::MIA, null], $this->waitingOn());
    }

    /**
     * @param array<string, string> $columnsBefore
     * @param array<string, string> $expectedPeople
     */
    #[Test]
    #[DataProvider('refusedMovesTo')]
    public function aRefusedMoveToSaysWhyAndChangesNothing(string $actor, string $stepId, array $columnsBefore, ?string $waitingOnUserId, ?string $waitingOnLabel, ?string $expectedTemplate, array $expectedPeople): void
    {
        $this->setColumns($columnsBefore);
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->moveTo($actor, $stepId, $stateBefore['version'], null === $waitingOnUserId ? null : new UserId($waitingOnUserId), $waitingOnLabel));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertSame($expectedTemplate, $refusal->ruleNoteTemplate);
        self::assertSame($expectedPeople, array_map(static fn (UserId $userId): string => $userId->toString(), $refusal->ruleNotePeople));
        self::assertSame($stateBefore, $this->storedState());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badWaitingOnLabels(): iterable
    {
        yield 'blank' => ["  \t ", 'A ticket waits on a person or a label; the label is blank.'];
        yield '101 characters' => [str_repeat('é', 101), 'A waiting-on label has at most 100 characters.'];
        yield 'a CRLF' => ["Cus\r\ntomer", 'A waiting-on label is one line of text.'];
        yield 'a U+2028 line separator' => ["Cus\u{2028}tomer", 'A waiting-on label is one line of text.'];
        yield 'a byte order mark' => ["\u{FEFF}Customer", 'A waiting-on label is one line of text.'];
    }

    #[Test]
    #[DataProvider('badWaitingOnLabels')]
    public function aBadLabelToWaitOnIsRefusedAndChangesNothing(string $waitingOnLabel, string $expectedMessage): void
    {
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->moveTo(self::LENA, self::NEEDS_INPUT_STEP, $stateBefore['version'], null, $waitingOnLabel));

        self::assertInstanceOf(WaitingOnRefused::class, $refusal);
        self::assertSame($expectedMessage, $refusal->getMessage());
        self::assertSame($stateBefore, $this->storedState());
    }

    #[Test]
    public function aPersonAndALabelToWaitOnTogetherAreRefused(): void
    {
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->moveTo(self::LENA, self::NEEDS_INPUT_STEP, $stateBefore['version'], new UserId(self::RAJ), 'Customer'));

        self::assertInstanceOf(WaitingOnRefused::class, $refusal);
        self::assertSame('A ticket waits on a person or a label, not both.', $refusal->getMessage());
        self::assertSame($stateBefore, $this->storedState());
    }

    #[Test]
    public function aStaleVersionIsRefusedAsChangedMeanwhile(): void
    {
        $stateBefore = $this->storedState();

        $verbRefusal = $this->refusalOf(fn () => $this->move(self::OMAR, 'start_work', $stateBefore['version'] - 1));
        $moveToRefusal = $this->refusalOf(fn () => $this->moveTo(self::LENA, self::IN_PROGRESS_STEP, $stateBefore['version'] + 1));

        self::assertInstanceOf(TicketChangedMeanwhile::class, $verbRefusal);
        self::assertInstanceOf(TicketChangedMeanwhile::class, $moveToRefusal);
        self::assertSame($stateBefore, $this->storedState());
    }

    /**
     * Another request moves the ticket on its own connection after this one read it, so this one's save is refused and
     * writes nothing; the other's move stays.
     */
    #[Test]
    public function whenAnotherRequestMovesTheTicketAfterItWasReadOnlyThatMoveIsSaved(): void
    {
        $stateBefore = $this->storedState();
        $moveTicket = $this->moveTicketWith(new readonly class($this->entityManager(), $this->migrator(), self::ON_HOLD_STEP) implements Tickets {
            public function __construct(private EntityManagerInterface $entityManager, private Connection $migrator, private string $otherRequestsStepId)
            {
            }

            public function nextIdentity(): TicketId
            {
                return new DoctrineTickets($this->entityManager)->nextIdentity();
            }

            public function add(Ticket $ticket): void
            {
                new DoctrineTickets($this->entityManager)->add($ticket);
            }

            public function save(Ticket $ticket): void
            {
                new DoctrineTickets($this->entityManager)->save($ticket);
            }

            public function ofKey(OrganizationId $organizationId, TicketKey $key): ?Ticket
            {
                $ticket = new DoctrineTickets($this->entityManager)->ofKey($organizationId, $key);
                $this->migrator->transactional(fn (Connection $connection): int|string => $connection->executeStatement(
                    'UPDATE tkt_ticket SET step_id = ?, previous_step_id = step_id, version = version + 1 WHERE key = ? AND organization_id = ?',
                    [$this->otherRequestsStepId, $key->toString(), $organizationId->toString()],
                ));

                return $ticket;
            }
        });

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $moveTicket->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            self::KEY,
            'start_work',
            $stateBefore['version'],
            new \DateTimeImmutable(self::MOVED_AT),
        )));

        self::assertInstanceOf(TicketChangedMeanwhile::class, $refusal, (string) $refusal);
        self::assertSame(
            ['step_id' => self::ON_HOLD_STEP, 'version' => $stateBefore['version'] + 1] + $stateBefore,
            $this->storedState(),
        );
    }

    #[Test]
    public function aThrowAfterTheEventIsWrittenLeavesNoMoveNoHistoryNoEventAndTheTurnRowAsItWas(): void
    {
        $stateBefore = $this->storedState();

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, function () use ($stateBefore): never {
            $this->ticketCommands()->move(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::KEY, 'start_work', $stateBefore['version'], new \DateTimeImmutable(self::MOVED_AT));
            self::assertCount(1, $this->movedEvents());
            throw new \RuntimeException('Forced after the publish.');
        }));

        self::assertInstanceOf(\RuntimeException::class, $refusal);
        self::assertSame('Forced after the publish.', $refusal->getMessage());
        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame($stateBefore, $this->storedState());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keysAcmeFoodsHasNot(): iterable
    {
        yield 'a key only Beta Bakery has' => [self::ACME_FOODS, 'SHOP-8'];
        yield 'a malformed key' => [self::ACME_FOODS, "SHOP-7\n"];
        yield 'a key with number 0' => [self::ACME_FOODS, 'SHOP-0'];
    }

    #[Test]
    #[DataProvider('keysAcmeFoodsHasNot')]
    public function aKeyTheOrganizationHasNoTicketOfIsNotFound(string $organizationId, string $key): void
    {
        $stateBefore = $this->storedState();

        $verbRefusal = $this->refusalOf(fn () => $this->transactions()->committedAs($organizationId, fn () => $this->ticketCommands()->move(
            new OrganizationId($organizationId),
            new UserId(self::LENA),
            $key,
            'put_on_hold',
            $stateBefore['version'],
            new \DateTimeImmutable(self::MOVED_AT),
        )));
        $moveToRefusal = $this->refusalOf(fn () => $this->transactions()->committedAs($organizationId, fn () => $this->ticketCommands()->moveTo(
            new OrganizationId($organizationId),
            new UserId(self::LENA),
            $key,
            self::ON_HOLD_STEP,
            $stateBefore['version'],
            null,
            null,
            new \DateTimeImmutable(self::MOVED_AT),
        )));

        self::assertInstanceOf(TicketNotFound::class, $verbRefusal);
        self::assertInstanceOf(TicketNotFound::class, $moveToRefusal);
        self::assertSame($stateBefore, $this->storedState());
        $this->assertBetaTicketsUntouched();
    }

    #[Test]
    public function acmeFoodsMovingItsKeyLeavesBetaBakerysTicketOfTheSameKeyAlone(): void
    {
        $versionBefore = $this->stepAndVersion()[1];

        $this->move(self::LENA, 'put_on_hold', $versionBefore);

        self::assertSame(self::ON_HOLD_STEP, $this->stepAndVersion()[0]);
        $this->assertBetaTicketsUntouched();
    }

    /**
     * The team's steps, people and rules come from the caches the first move filled, so a verb move reads the ticket
     * and the team's waiting reviews and tests, then saves, projects and publishes.
     */
    #[Test]
    public function aWarmVerbMoveRunsEightQueriesBesideItsSavepoints(): void
    {
        $versionBefore = $this->stepAndVersion()[1];

        $statements = $this->statementsOfAWarmMove(
            fn () => $this->ticketCommands()->move(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::KEY, 'start_work', $versionBefore, new \DateTimeImmutable(self::MOVED_AT)),
            fn () => $this->ticketCommands()->move(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::KEY, 'send_to_code_review', $versionBefore + 1, new \DateTimeImmutable(self::MOVED_AT)),
        );

        self::assertStatementsStartWith([self::TICKET_BY_KEY, self::WAITING_TURNS, self::SAVED, ...self::PROJECTED_AND_PUBLISHED], $statements);
        self::assertCount(8, self::queriesBesideSavepoints($statements), 'P03.T7: a move uses at most 8 queries');
    }

    #[Test]
    public function aWarmMoveToAPlainStepRunsEightQueriesBesideItsSavepoints(): void
    {
        $versionBefore = $this->stepAndVersion()[1];

        $statements = $this->statementsOfAWarmMove(
            fn () => $this->ticketCommands()->moveTo(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::KEY, self::IN_PROGRESS_STEP, $versionBefore, null, null, new \DateTimeImmutable(self::MOVED_AT)),
            fn () => $this->ticketCommands()->moveTo(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::KEY, self::ON_HOLD_STEP, $versionBefore + 1, null, null, new \DateTimeImmutable(self::MOVED_AT)),
        );

        self::assertStatementsStartWith([self::TICKET_BY_KEY, self::WAITING_TURNS, self::SAVED, ...self::PROJECTED_AND_PUBLISHED], $statements);
        self::assertCount(8, self::queriesBesideSavepoints($statements), 'P03.T7: a move uses at most 8 queries');
    }

    /**
     * Raj is in the team, but who a ticket waits on may be any member of the organization, so his membership is read.
     */
    #[Test]
    public function aWarmMoveToNeedsInputWaitingOnAPersonRunsNineQueriesBesideItsSavepoints(): void
    {
        $versionBefore = $this->stepAndVersion()[1];

        $statements = $this->statementsOfAWarmMove(
            fn () => $this->ticketCommands()->moveTo(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::KEY, self::IN_PROGRESS_STEP, $versionBefore, null, null, new \DateTimeImmutable(self::MOVED_AT)),
            fn () => $this->ticketCommands()->moveTo(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::KEY, self::NEEDS_INPUT_STEP, $versionBefore + 1, new UserId(self::RAJ), null, new \DateTimeImmutable(self::MOVED_AT)),
        );

        self::assertStatementsStartWith([self::TICKET_BY_KEY, 'SELECT organization_id FROM resolve_user_orgs(?)', self::WAITING_TURNS, self::SAVED, ...self::PROJECTED_AND_PUBLISHED], $statements);
        // Nine, not eight: no event clears the cached team people when a membership ends, so they cannot vouch for Raj.
        self::assertCount(9, self::queriesBesideSavepoints($statements), 'P03.T7: the move plus the waited-on person\'s membership check');
    }

    /**
     * With nobody named the ticket waits on Mia, its reporter, whose membership is read as a named person's is.
     */
    #[Test]
    public function aWarmMoveToNeedsInputWaitingOnItsReporterRunsNineQueriesBesideItsSavepoints(): void
    {
        $versionBefore = $this->stepAndVersion()[1];

        $statements = $this->statementsOfAWarmMove(
            fn () => $this->ticketCommands()->moveTo(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::KEY, self::IN_PROGRESS_STEP, $versionBefore, null, null, new \DateTimeImmutable(self::MOVED_AT)),
            fn () => $this->ticketCommands()->moveTo(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::KEY, self::NEEDS_INPUT_STEP, $versionBefore + 1, null, null, new \DateTimeImmutable(self::MOVED_AT)),
        );

        self::assertStatementsStartWith([self::TICKET_BY_KEY, 'SELECT organization_id FROM resolve_user_orgs(?)', self::WAITING_TURNS, self::SAVED, ...self::PROJECTED_AND_PUBLISHED], $statements);
        self::assertCount(9, self::queriesBesideSavepoints($statements), 'P03.T7: the move plus the reporter\'s membership check');
    }

    /**
     * @param list<string> $expectedPrefixes
     * @param list<string> $statements
     */
    private static function assertStatementsStartWith(array $expectedPrefixes, array $statements): void
    {
        $sentPrefixes = [];
        foreach ($statements as $index => $statement) {
            $sentPrefixes[] = substr($statement, 0, \strlen($expectedPrefixes[$index] ?? $statement));
        }
        self::assertSame($expectedPrefixes, $sentPrefixes);
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string> the statements that are neither a savepoint nor its release
     */
    private static function queriesBesideSavepoints(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (string $statement): bool => !str_starts_with($statement, 'SAVEPOINT ') && !str_starts_with($statement, 'RELEASE SAVEPOINT '),
        ));
    }

    /**
     * Both moves run in one transaction under Acme Foods, as a request's would, and are rolled back. The first fills
     * the caches; the entity manager is cleared after it, as a new request starts with an empty one.
     *
     * @param \Closure(): void $warmingMove
     * @param \Closure(): void $measuredMove
     *
     * @return list<string> the SQL of each statement of the measured move, in the order sent
     */
    private function statementsOfAWarmMove(\Closure $warmingMove, \Closure $measuredMove): array
    {
        self::mockTime(self::MOVED_AT);
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($warmingMove, $measuredMove, $debugData): array {
            $warmingMove();
            $this->entityManager()->clear();
            $debugData->reset();
            $measuredMove();

            return self::sqlOf($debugData);
        });
    }

    private function assertMoved(string $stepBefore, int $versionBefore, string $expectedStepId, string $actor, ?string $verb): void
    {
        self::assertSame([$expectedStepId, $versionBefore + 1], $this->stepAndVersion());
        self::assertSame(
            [[
                'actor_id' => $actor,
                'from_step' => $stepBefore,
                'to_step' => $expectedStepId,
                'verb' => $verb,
                'at' => '2027-03-16 09:30:00',
            ]],
            $this->movedRows(),
        );
        $ticketId = $this->ticketId();
        $bodies = $this->outboxBodies();
        self::assertCount(1, $bodies);
        self::assertStringNotContainsString('Cart total', $bodies[0]);
        $event = $this->movedEvents()[0] ?? null;
        self::assertInstanceOf(TicketMoved::class, $event);
        self::assertSame(
            [self::ACME_FOODS, $ticketId->toString(), self::KEY, $stepBefore, $expectedStepId, $verb, $actor, '2027-03-16T09:30:00+00:00'],
            [$event->organizationId, $event->ticketId, $event->key, $event->fromStepId, $event->toStepId, $event->verb, $event->actorId, $event->movedAt->format(\DATE_ATOM)],
        );
        $turnRowsAfterTheMove = $this->turnRows();
        self::assertSame(
            self::DONE_STEP === $expectedStepId ? [] : [$this->groupOf($expectedStepId)],
            array_column($turnRowsAfterTheMove, 'grp'),
        );
        $isDone = self::DONE_STEP === $expectedStepId;
        $countRowsAfterTheMove = $this->projectCountRows();
        self::assertSame(
            [
                'placement' => [[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', $isDone]],
                'counts' => [[self::SHOP_PROJECT, self::SHOP_TEAM, 'all', $isDone ? 0 : 1, $isDone ? 1 : 0]],
            ],
            $countRowsAfterTheMove,
        );
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($ticketId));
        self::assertSame($this->turnRows(), $turnRowsAfterTheMove);
        self::assertSame($this->projectCountRows(), $countRowsAfterTheMove);
    }

    private function assertBetaTicketsUntouched(): void
    {
        self::assertSame(
            [['key' => 'SHOP-7', 'step_id' => ShopTeamRows::TO_DO_STEP, 'version' => 1], ['key' => 'SHOP-8', 'step_id' => ShopTeamRows::TO_DO_STEP, 'version' => 1]],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->connection()->fetchAllAssociative('SELECT key, step_id, version FROM tkt_ticket ORDER BY key')),
        );
    }

    private function move(string $actor, string $verb, int $expectedVersion): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId($actor),
            self::KEY,
            $verb,
            $expectedVersion,
            new \DateTimeImmutable(self::MOVED_AT),
        ));
    }

    private function moveTo(string $actor, string $stepId, int $expectedVersion, ?UserId $waitingOnUserId = null, ?string $waitingOnLabel = null): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->moveTo(
            new OrganizationId(self::ACME_FOODS),
            new UserId($actor),
            self::KEY,
            $stepId,
            $expectedVersion,
            $waitingOnUserId,
            $waitingOnLabel,
            new \DateTimeImmutable(self::MOVED_AT),
        ));
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function refusalOf(\Closure $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $refusal) {
            self::getContainer()->get('doctrine')->resetManager();

            return $refusal;
        }

        return null;
    }

    /**
     * @param array<string, string|null> $columns
     */
    private function setColumns(array $columns): void
    {
        if ([] === $columns) {
            return;
        }
        $assignments = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($columns)));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET '.$assignments.' WHERE key = ?',
            [...array_values($columns), self::KEY],
        ));
    }

    private function addRuleSet(bool $reviewBeforeTest): void
    {
        $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $connection): int|string => $connection->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, NULL, true, true, ?)',
            [self::ACME_FOODS, $reviewBeforeTest ? 'true' : 'false'],
        ));
    }

    private function addAcmeSteps(): void
    {
        $this->asMigratorOf(self::ACME_FOODS, static function (Connection $connection): void {
            $connection->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, 'Warehouse', 'warehouse', ?, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::WAREHOUSE_TEAM, self::LENA],
            );
            foreach ([
                [self::IN_PROGRESS_STEP, null, 'In progress', 'doing', 'progress', 'core', 0],
                [self::CODE_REVIEW_STEP, null, 'Code review', 'check', 'review', 'core', 0],
                [self::TESTING_STEP, null, 'Testing', 'check', 'test', 'core', 1],
                [self::NEEDS_INPUT_STEP, null, 'Needs input', 'wait', 'input', 'core', 0],
                [self::ON_HOLD_STEP, null, 'On hold', 'wait', 'hold', 'core', 1],
                [self::DONE_STEP, null, 'Done', 'done', 'done', 'core', 0],
                [self::PAIRING_STEP, self::SHOP_TEAM, 'Pairing', 'doing', null, 'team', 5],
                [self::WAREHOUSE_STEP, self::WAREHOUSE_TEAM, 'Packing', 'doing', null, 'team', 5],
            ] as [$stepId, $teamId, $name, $groupCode, $coreCode, $origin, $position]) {
                $connection->executeStatement(
                    'INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [self::ACME_FOODS, $stepId, $teamId, $name, $groupCode, $coreCode, $origin, $position],
                );
            }
        });
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asMigratorOf(string $organizationId, \Closure $work): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * @return array{step_id: string, version: int, history: list<array<string, mixed>>, outbox: int, turn: list<array<string, mixed>>, project_counts: array{placement: list<list<mixed>>, counts: list<list<mixed>>}}
     */
    private function storedState(): array
    {
        [$stepId, $version] = $this->stepAndVersion();

        return ['step_id' => $stepId, 'version' => $version, 'history' => $this->movedRows(), 'outbox' => $this->outboxRows(), 'turn' => $this->turnRows(), 'project_counts' => $this->projectCountRows()];
    }

    /**
     * @return array{placement: list<list<mixed>>, counts: list<list<mixed>>} the count row Acme Foods' SHOP-7 is placed
     *                                                                        in, and Acme Foods' count rows
     */
    private function projectCountRows(): array
    {
        $ticketId = $this->ticketId()->toString();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            'placement' => $this->connection()->fetchAllNumeric('SELECT project_id, team_id, visibility, is_done FROM ins_project_count_ticket WHERE ticket_id = ?', [$ticketId]),
            'counts' => $this->connection()->fetchAllNumeric('SELECT project_id, team_id, visibility, open_count, done_count FROM ins_project_counts ORDER BY project_id, team_id, visibility'),
        ]);
    }

    /**
     * @return array{string, int}
     */
    private function stepAndVersion(): array
    {
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative('SELECT step_id, version FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsArray($row);
        self::assertIsString($row['step_id']);
        self::assertIsInt($row['version']);

        return [$row['step_id'], $row['version']];
    }

    private function ticketId(): TicketId
    {
        $ticketId = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT id FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsString($ticketId);

        return new TicketId($ticketId);
    }

    private function groupOf(string $stepId): string
    {
        $groupCode = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT grp FROM wf_step WHERE id = ?', [$stepId]));
        self::assertIsString($groupCode);

        return $groupCode;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movedRows(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT actor_id, from_step, to_step, payload->>'verb' AS verb, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS at
             FROM tkt_ticket_event WHERE type = 'moved' ORDER BY at, id",
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function turnRows(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT ticket_id, turn_user_id, turn_label, kind, grp FROM ins_ticket_turn ORDER BY ticket_id',
        ));
    }

    private function outboxRows(): int
    {
        $count = $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<string> the body of each outbox row, oldest first
     */
    private function outboxBodies(): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' ORDER BY id") as $body) {
            self::assertIsString($body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    /**
     * @return list<TicketMoved> the TicketMoved events in the outbox, decoded as the outbox transport does, oldest first
     */
    private function movedEvents(): array
    {
        $serializer = new PhpSerializer();
        $events = [];
        foreach ($this->outboxBodies() as $body) {
            $message = $serializer->decode(['body' => $body])->getMessage();
            if ($message instanceof TicketMoved) {
                $events[] = $message;
            }
        }

        return $events;
    }

    /**
     * Mia, until now the tester, becomes a developer of Shop, which then has no tester, and Omar reviews another
     * ticket, so he has one waiting review and she none.
     *
     * @return TicketId Omar's review
     */
    private function makeMiaADeveloperWhileOmarReviewsAnotherTicket(): TicketId
    {
        self::mockTime(self::MOVED_AT);
        $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $connection): int|string => $connection->executeStatement(
            "UPDATE org_team_member SET job = 'developer' WHERE organization_id = ? AND team_id = ? AND user_id = ?",
            [self::ACME_FOODS, self::SHOP_TEAM, self::MIA],
        ));
        self::getContainer()->get(TeamPeopleCache::class)->invalidateTeamNow(new TeamId(self::SHOP_TEAM));
        $omarsReviewId = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection())->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::LENA);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET step_id = ?, reviewer_id = ? WHERE id = ?',
            [self::CODE_REVIEW_STEP, self::OMAR, $omarsReviewId->toString()],
        ));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($omarsReviewId));
        $ticketId = $this->ticketId();
        self::assertSame(
            [self::OMAR => [TurnInputs::WAITING_REVIEW => 1]],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::getContainer()->get(TeamLoadQueries::class)->waitingTurnsOf(
                [new UserId(self::LENA), new UserId(self::OMAR), new UserId(self::MIA)],
                $ticketId,
            )),
        );

        return $omarsReviewId;
    }

    /**
     * The other ticket goes back to In progress and its turn row is refreshed, so, SHOP-7 aside, nobody has a review or
     * test waiting and the loads tie.
     */
    private function moveOutOfItsCheckStep(TicketId $otherTicketId): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET step_id = ? WHERE id = ?',
            [self::IN_PROGRESS_STEP, $otherTicketId->toString()],
        ));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($otherTicketId));
        $ticketId = $this->ticketId();
        self::assertSame(
            [],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::getContainer()->get(TeamLoadQueries::class)->waitingTurnsOf(
                [new UserId(self::LENA), new UserId(self::OMAR), new UserId(self::MIA), new UserId(self::RAJ)],
                $ticketId,
            )),
        );
    }

    /**
     * @return array<string, mixed> the turn_user_id and kind of the ticket's turn row, or none without one
     */
    private function turnOf(TicketId $ticketId): array
    {
        $turn = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative('SELECT turn_user_id, kind FROM ins_ticket_turn WHERE ticket_id = ?', [$ticketId->toString()]));

        return false === $turn ? [] : $turn;
    }

    /**
     * @return array{string|null, string|null} the reviewer_id and tester_id of Acme Foods' SHOP-7
     */
    private function storedReviewerAndTester(): array
    {
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchNumeric('SELECT reviewer_id, tester_id FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsArray($row);
        self::assertTrue(null === $row[0] || \is_string($row[0]));
        self::assertTrue(null === $row[1] || \is_string($row[1]));

        return [$row[0], $row[1]];
    }

    /**
     * Raj, until now a viewer, becomes a tester member of Shop, and Mia tests another ticket, so she has one waiting
     * test and he none.
     *
     * @return TicketId Mia's test
     */
    private function makeRajASecondTesterWhileMiaTestsAnotherTicket(): TicketId
    {
        self::mockTime(self::MOVED_AT);
        $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $connection): int|string => $connection->executeStatement(
            "UPDATE org_team_member SET team_role = 'member', job = 'tester' WHERE organization_id = ? AND team_id = ? AND user_id = ?",
            [self::ACME_FOODS, self::SHOP_TEAM, self::RAJ],
        ));
        self::getContainer()->get(TeamPeopleCache::class)->invalidateTeamNow(new TeamId(self::SHOP_TEAM));
        $miasTestId = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection())->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, self::OMAR);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET step_id = ?, tester_id = ? WHERE id = ?',
            [self::TESTING_STEP, self::MIA, $miasTestId->toString()],
        ));
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->turnProjector()->refresh($miasTestId));
        $ticketId = $this->ticketId();
        self::assertSame(
            [self::MIA => [TurnInputs::WAITING_TEST => 1]],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => self::getContainer()->get(TeamLoadQueries::class)->waitingTurnsOf(
                [new UserId(self::MIA), new UserId(self::RAJ)],
                $ticketId,
            )),
        );

        return $miasTestId;
    }

    private function removeMembershipOf(string $userId): void
    {
        $this->asMigratorOf(self::ACME_FOODS, static fn (Connection $connection): int|string => $connection->executeStatement(
            'DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [self::ACME_FOODS, $userId],
        ));
    }

    /**
     * @return array{string|null, string|null} the waiting_on_user_id and waiting_on_label of Acme Foods' SHOP-7
     */
    private function waitingOn(): array
    {
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchNumeric('SELECT waiting_on_user_id, waiting_on_label FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsArray($row);
        self::assertTrue(null === $row[0] || \is_string($row[0]));
        self::assertTrue(null === $row[1] || \is_string($row[1]));

        return [$row[0], $row[1]];
    }

    private function policyAllowingEveryVerbToNeedsInput(): TransitionPolicy
    {
        return new readonly class(self::getContainer()->get(TransitionPolicy::class)) implements TransitionPolicy {
            public function __construct(private TransitionPolicy $catalogue)
            {
            }

            public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): Availability
            {
                return $this->catalogue->available($ticket, $actor, $rules, $team);
            }

            public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team, string $verb): Decision
            {
                return Decision::allowed(MoveTarget::coreStep('input'));
            }

            public function moveChoices(TicketState $ticket, ActorInTeam $actor, array $steps): MoveChoices
            {
                return $this->catalogue->moveChoices($ticket, $actor, $steps);
            }

            public function checkMoveTo(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, StepView $target, array $steps): Decision
            {
                return $this->catalogue->checkMoveTo($ticket, $actor, $rules, $target, $steps);
            }
        };
    }

    private function moveTicketWith(Tickets $tickets, ?TransitionPolicy $transitionPolicy = null): MoveTicket
    {
        $container = self::getContainer();
        $snapshots = $container->get('test.ticketing_ticket_turn_snapshots');
        self::assertInstanceOf(TicketTurnSnapshots::class, $snapshots);

        return new MoveTicket(
            $tickets,
            $snapshots,
            $container->get(TeamLoadQueries::class),
            $container->get(TeamPeopleQueries::class),
            $container->get(MembershipQueries::class),
            $container->get(RuleQueries::class),
            $container->get(StepQueries::class),
            $transitionPolicy ?? $container->get(TransitionPolicy::class),
            new DbalTicketHistory($this->connection()),
            $this->turnProjector(),
            new MessengerTicketEvents($container->get('event.bus'), $this->connection()),
            $container->get(TurnPeopleChoice::class),
        );
    }

    private function turnProjector(): TurnProjector
    {
        $turnProjector = self::getContainer()->get('test.insight_turn_projector');
        self::assertInstanceOf(TurnProjector::class, $turnProjector);

        return $turnProjector;
    }

    private function ticketCommands(): TicketCommands
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return $ticketCommands;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ, self::SARA], self::ACME_FOODS, self::BETA_BAKERY);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
