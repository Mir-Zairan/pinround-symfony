<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\QuestionRefused;
use App\Conversation\Application\Answer\Answer;
use App\Conversation\Application\AskQuestion\AskQuestion;
use App\Conversation\Application\PostedMessage;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Application\VisibleTickets;
use App\Conversation\Application\WithdrawQuestion\WithdrawQuestion;
use App\Conversation\Infrastructure\Doctrine\DoctrineMessages;
use App\Conversation\Infrastructure\Doctrine\DoctrineReplies;
use App\Conversation\Infrastructure\Messenger\MessengerConversationEvents;
use App\Insight\Api\TurnProjector;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\ShopTickets;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPauses;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Application\LockedTicketPauses;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * AskQuestion, Answer and WithdrawQuestion on the real tables, outbox and turn projector, as pinround_app under
 * row-level security. Acme Foods' SHOP-7 starts in To do, reported by Mia and assigned to Omar, so it is Omar's turn;
 * Lena leads the team and Raj is on it but not on the ticket. Beta Bakery has a SHOP-7 of its own. A refusal that
 * comes before any write is caught inside a transaction that then commits, so "unchanged" proves the check comes first.
 */
final class QuestionUseCasesTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e601';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e602';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e611';
    private const string BETA_TEAM = '0197a3c0-0000-7000-8000-00000000e612';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e621';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000e622';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e651';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e652';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e653';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000e654';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000e655';
    private const string SEEDED_QUESTION = '0197a3c0-0000-7000-8000-00000000e661';
    private const string QUESTION_BODY = 'Which browser shows the wrong sum?';
    private const string ANSWER_BODY = 'Only the oldest one on the shop tablets.';
    private const string ASKED_AT = '2027-03-16 11:45:00+00:00';
    private const string ANSWERED_AT = '2027-03-16 14:10:00+00:00';

    private TicketId $ticketId;
    private TicketId $betaTicketId;

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        self::mockTime('2027-03-16 09:30:00+00:00');
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'member', 'other'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::BETA_TEAM, self::BETA_PROJECT, self::SARA, [self::SARA => ['Sara', 'lead', 'developer']]);
        $shopTickets = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection());
        $this->ticketId = $shopTickets->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $this->betaTicketId = $shopTickets->createdFor(self::BETA_BAKERY, self::BETA_PROJECT, self::SARA, self::SARA);
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function askingPausesTheTicketAndGivesTheTurnToTheAskedPersonToAnswer(): void
    {
        $before = $this->ticketStateOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(['turn_user_id' => self::OMAR, 'kind' => 'build'], $this->turnOf($this->ticketId, self::ACME_FOODS));

        $questionId = $this->askedByOmarOfMia();

        self::assertSame(
            ['asked_user_id' => self::MIA, 'asker_id' => self::OMAR, 'step_id' => $before['step_id'], 'version' => $before['version'] + 1],
            $this->ticketStateOf($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame(['turn_user_id' => self::MIA, 'kind' => 'answer'], $this->turnOf($this->ticketId, self::ACME_FOODS));
        self::assertSame(
            [[$questionId, 'question', self::OMAR, self::MIA, self::QUESTION_BODY, '2027-03-16 11:45:00+00', null, null, null]],
            $this->messagesOf($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame(['QuestionAsked'], $this->conversationEventsNaming($this->ticketId));
        $questionAsked = $this->outboxBodiesNaming($questionId);
        self::assertCount(1, $questionAsked);
        self::assertStringContainsString(self::MIA, $questionAsked[0]);
        self::assertStringNotContainsString(self::QUESTION_BODY, $questionAsked[0]);
    }

    #[Test]
    public function answeringResumesTheTicketGivesTheTurnBackAndWritesTheAnswerReply(): void
    {
        $turnBefore = $this->turnOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(self::OMAR, $turnBefore['turn_user_id'] ?? null);
        $questionId = $this->askedByOmarOfMia();
        $asked = $this->ticketStateOf($this->ticketId, self::ACME_FOODS);

        $replyId = $this->transactions()->committedAs(self::ACME_FOODS, fn (): PostedMessage => $this->answer()->answer(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::MIA),
            '  '.self::ANSWER_BODY.' ',
            new \DateTimeImmutable(self::ANSWERED_AT),
        ))->id;

        self::assertSame(
            ['asked_user_id' => null, 'asker_id' => null, 'step_id' => $asked['step_id'], 'version' => $asked['version'] + 1],
            $this->ticketStateOf($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame($turnBefore, $this->turnOf($this->ticketId, self::ACME_FOODS));
        self::assertSame(
            [[$questionId, 'question', self::OMAR, self::MIA, self::QUESTION_BODY, '2027-03-16 11:45:00+00', '2027-03-16 14:10:00+00', self::MIA, null]],
            $this->messagesOf($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame([[$replyId, $questionId, self::MIA, self::ANSWER_BODY, true]], $this->repliesOf($this->ticketId, self::ACME_FOODS));
        self::assertSame(['QuestionAsked', 'QuestionAnswered'], $this->conversationEventsNaming($this->ticketId));
        foreach ($this->outboxBodiesNaming($this->ticketId->toString()) as $outboxBody) {
            self::assertStringNotContainsString(self::ANSWER_BODY, $outboxBody);
        }
    }

    #[Test]
    public function aFailureAfterTheResumeRollsBackTheAnswerTheResumeAndTheTurn(): void
    {
        $questionId = $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(['turn_user_id' => self::MIA, 'kind' => 'answer'], $asked['turn']);

        $failure = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, fn (): PostedMessage => $this->answerThatFailsAfterTheTurnRefresh()->answer(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::MIA),
            self::ANSWER_BODY,
            new \DateTimeImmutable(self::ANSWERED_AT),
        )));
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([\RuntimeException::class, 'The work after the turn refresh failed.'], [$failure::class, $failure->getMessage()]);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aFailureAfterTheResumeRollsBackTheWithdrawalTheResumeAndTheTurn(): void
    {
        $questionId = $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(['asked_user_id' => self::MIA, 'asker_id' => self::OMAR], array_intersect_key(
            $asked['ticket'],
            ['asked_user_id' => true, 'asker_id' => true],
        ));
        self::assertSame(['turn_user_id' => self::MIA, 'kind' => 'answer'], $asked['turn']);

        $failure = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->withdrawQuestionThatFailsAfterTheTurnRefresh()->withdraw(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::OMAR),
            new \DateTimeImmutable(self::ANSWERED_AT),
        )));
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([\RuntimeException::class, 'The work after the turn refresh failed.'], [$failure::class, $failure->getMessage()]);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function withdrawingResumesTheTicketAndGivesTheTurnBackWithOneMessageRemovedInTheOutbox(): void
    {
        $turnBefore = $this->turnOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(self::OMAR, $turnBefore['turn_user_id'] ?? null);
        $questionId = $this->askedByOmarOfMia();
        $asked = $this->ticketStateOf($this->ticketId, self::ACME_FOODS);

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->withdrawQuestion()->withdraw(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::OMAR),
            new \DateTimeImmutable(self::ANSWERED_AT),
        ));

        self::assertSame(
            ['asked_user_id' => null, 'asker_id' => null, 'step_id' => $asked['step_id'], 'version' => $asked['version'] + 1],
            $this->ticketStateOf($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame($turnBefore, $this->turnOf($this->ticketId, self::ACME_FOODS));
        self::assertSame(
            [[$questionId, 'question', self::OMAR, self::MIA, self::QUESTION_BODY, '2027-03-16 11:45:00+00', null, null, '2027-03-16 14:10:00+00']],
            $this->messagesOf($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame([], $this->repliesOf($this->ticketId, self::ACME_FOODS));
        self::assertSame(['QuestionAsked', 'MessageRemoved'], $this->conversationEventsNaming($this->ticketId));
    }

    #[Test]
    public function aSecondQuestionOnAPausedTicketIsRefusedAndNothingChanges(): void
    {
        $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);
        self::assertCount(1, $asked['messages']);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestion()->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::LENA),
            new UserId(self::OMAR),
            'Is this on the release list?',
            new \DateTimeImmutable('2027-03-16 12:00:00+00:00'),
        )));

        self::assertSame([QuestionRefused::class, 'This ticket already waits for an answer.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aQuestionTheThreadStillHoldsOpenRefusesASecondThroughItsIndexAndNothingChanges(): void
    {
        $this->threadRows()->addOpenQuestionOf(self::ACME_FOODS, $this->ticketId->toString(), self::SEEDED_QUESTION, self::LENA, self::OMAR, self::QUESTION_BODY, '2027-03-16 08:00:00+00');
        $before = $this->stateOf($this->ticketId, self::ACME_FOODS);
        self::assertSame([null, null], [$before['ticket']['asked_user_id'], $before['ticket']['asker_id']]);
        self::assertCount(1, $before['messages']);

        // The unique violation aborts PostgreSQL's transaction, so the refusal leaves it and the transaction rolls back.
        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, fn (): PostedMessage => $this->askQuestion()->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::OMAR),
            new UserId(self::MIA),
            self::QUESTION_BODY,
            new \DateTimeImmutable(self::ASKED_AT),
        )));
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([QuestionRefused::class, 'This ticket already waits for an answer.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame($before, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function askingSomeoneNotOnTheTicketIsRefusedAndNothingChanges(): void
    {
        $before = $this->stateOf($this->ticketId, self::ACME_FOODS);

        $refusals = [];
        foreach ([[self::OMAR, self::RAJ], [self::OMAR, self::SARA]] as [$askerId, $askedUserId]) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestion()->ask(
                $this->ticketId,
                $this->acmeTicketFacts(),
                new UserId($askerId),
                new UserId($askedUserId),
                self::QUESTION_BODY,
                new \DateTimeImmutable(self::ASKED_AT),
            )));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 2, [QuestionRefused::class, 'You can ask only someone on this ticket.']), $refusals);
        self::assertSame($before, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function askingYourselfIsRefusedWithItsOwnMessageAndNothingChanges(): void
    {
        $before = $this->stateOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(['turn_user_id' => self::OMAR, 'kind' => 'build'], $before['turn']);

        // Omar is the assignee, so only the self-ask rule can refuse him.
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestion()->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::OMAR),
            new UserId(self::OMAR),
            self::QUESTION_BODY,
            new \DateTimeImmutable(self::ASKED_AT),
        )));

        self::assertSame([QuestionRefused::class, 'You cannot ask yourself.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame($before, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function askingTheFormerAssigneeOfATicketGivenAwayBeforeThePauseLocksItIsRefusedAndNothingIsWritten(): void
    {
        $before = $this->stateOf($this->ticketId, self::ACME_FOODS);
        self::assertSame(['turn_user_id' => self::OMAR, 'kind' => 'build'], $before['turn']);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestionWhileTheTicketIsGivenTo(self::RAJ)->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::MIA),
            new UserId(self::OMAR),
            self::QUESTION_BODY,
            new \DateTimeImmutable(self::ASKED_AT),
        )));
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([QuestionRefused::class, 'You can ask only someone on this ticket.'], [$refusal::class, $refusal->getMessage()]);
        $givenAway = $before;
        ++$givenAway['ticket']['version'];
        self::assertSame($givenAway, $this->stateOf($this->ticketId, self::ACME_FOODS), 'Only the second session\'s reassignment was saved.');
        self::assertSame([self::RAJ], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT assignee_id FROM tkt_ticket WHERE id = ?',
            [$this->ticketId->toString()],
        )));
    }

    #[Test]
    public function askingTheTeamLeadIsAllowedThoughTheLeadIsNotNamedOnTheTicket(): void
    {
        $questionId = $this->transactions()->committedAs(self::ACME_FOODS, fn (): PostedMessage => $this->askQuestion()->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::OMAR),
            new UserId(self::LENA),
            self::QUESTION_BODY,
            new \DateTimeImmutable(self::ASKED_AT),
        ))->id;

        self::assertSame(['turn_user_id' => self::LENA, 'kind' => 'answer'], $this->turnOf($this->ticketId, self::ACME_FOODS));
        self::assertSame([$questionId], array_column($this->messagesOf($this->ticketId, self::ACME_FOODS), 0));
    }

    #[Test]
    public function someoneWhoCannotSeeATeamTicketGetsNotFoundAndNothingIsWritten(): void
    {
        $before = $this->stateOf($this->ticketId, self::ACME_FOODS);
        $teamTicket = new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::MIA), new UserId(self::OMAR));

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestion()->ask(
            $this->ticketId,
            $teamTicket,
            new UserId(self::SARA),
            new UserId(self::OMAR),
            self::QUESTION_BODY,
            new \DateTimeImmutable(self::ASKED_AT),
        )));

        self::assertSame([TicketNotFound::class, 'That ticket does not exist.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame($before, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function anotherOrganizationsTicketIsNotFoundAndNothingIsWritten(): void
    {
        $betaBefore = $this->stateOf($this->betaTicketId, self::BETA_BAKERY);
        self::assertNotSame([], $betaBefore['turn']);
        $betaTicket = new TicketVisibilityFacts(new OrganizationId(self::BETA_BAKERY), TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::BETA_TEAM), new UserId(self::SARA), new UserId(self::SARA));

        $refusals = [];
        // Beta's own facts under Acme's tenant, then facts that claim Acme for Beta's ticket id.
        foreach ([$betaTicket, $this->acmeTicketFacts()] as $facts) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestion()->ask(
                $this->betaTicketId,
                $facts,
                new UserId(self::OMAR),
                new UserId(self::SARA),
                self::QUESTION_BODY,
                new \DateTimeImmutable(self::ASKED_AT),
            )));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 2, [TicketNotFound::class, 'That ticket does not exist.']), $refusals);
        self::assertSame($betaBefore, $this->stateOf($this->betaTicketId, self::BETA_BAKERY));
    }

    #[Test]
    public function onlyThePersonAskedAnswersAndOnlyTheAskerWithdrawsAndNothingChangesOtherwise(): void
    {
        $questionId = $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);

        $refusals = [];
        foreach ([
            fn () => $this->answer()->answer(new OrganizationId(self::ACME_FOODS), $this->ticketId, $questionId, new UserId(self::OMAR), self::ANSWER_BODY, new \DateTimeImmutable(self::ANSWERED_AT)),
            fn () => $this->withdrawQuestion()->withdraw(new OrganizationId(self::ACME_FOODS), $this->ticketId, $questionId, new UserId(self::MIA), new \DateTimeImmutable(self::ANSWERED_AT)),
        ] as $action) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf($action));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame([
            [QuestionRefused::class, 'Only the person asked can answer this question.'],
            [QuestionRefused::class, 'Only the person who asked a question can withdraw it.'],
        ], $refusals);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aWithdrawnQuestionIsNotAnsweredAndTheTicketStaysResumed(): void
    {
        $questionId = $this->askedByOmarOfMia();
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->withdrawQuestion()->withdraw(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::OMAR),
            new \DateTimeImmutable('2027-03-16 12:00:00+00:00'),
        ));
        $withdrawn = $this->stateOf($this->ticketId, self::ACME_FOODS);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->answer()->answer(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::MIA),
            self::ANSWER_BODY,
            new \DateTimeImmutable(self::ANSWERED_AT),
        )));

        self::assertSame([QuestionRefused::class, 'This question was withdrawn.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame($withdrawn, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aBlankQuestionOrAnswerIsRefusedAndNothingChanges(): void
    {
        $before = $this->stateOf($this->ticketId, self::ACME_FOODS);
        $askRefusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->askQuestion()->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::OMAR),
            new UserId(self::MIA),
            " \r\n\t",
            new \DateTimeImmutable(self::ASKED_AT),
        )));
        $afterAskRefusal = $this->stateOf($this->ticketId, self::ACME_FOODS);
        $questionId = $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);

        $answerRefusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->answer()->answer(
            new OrganizationId(self::ACME_FOODS),
            $this->ticketId,
            $questionId,
            new UserId(self::MIA),
            '   ',
            new \DateTimeImmutable(self::ANSWERED_AT),
        )));

        self::assertSame([MessageBodyRefused::class, 'A message needs a body.'], [$askRefusal::class, $askRefusal->getMessage()]);
        self::assertSame([MessageBodyRefused::class, 'A reply needs a body.'], [$answerRefusal::class, $answerRefusal->getMessage()]);
        self::assertSame($before, $afterAskRefusal);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function anotherOrganizationsQuestionIsNotFoundToAnswerOrWithdrawAndStaysOpen(): void
    {
        $questionId = $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);

        $refusals = [];
        foreach ([
            fn () => $this->answer()->answer(new OrganizationId(self::ACME_FOODS), $this->ticketId, $questionId, new UserId(self::MIA), self::ANSWER_BODY, new \DateTimeImmutable(self::ANSWERED_AT)),
            fn () => $this->withdrawQuestion()->withdraw(new OrganizationId(self::ACME_FOODS), $this->ticketId, $questionId, new UserId(self::OMAR), new \DateTimeImmutable(self::ANSWERED_AT)),
        ] as $action) {
            $refusal = $this->transactions()->committedAs(self::BETA_BAKERY, fn (): \Throwable => $this->refusalOf($action));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 2, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
    }

    /**
     * Mia is asked on SHOP-7 and may answer there, but the question's id under another ticket of Acme Foods names no
     * message of that ticket: neither ticket changes.
     */
    #[Test]
    public function aQuestionAnsweredThroughAnotherTicketIsNotFoundAndBothTicketsStayAsTheyAre(): void
    {
        $otherTicketId = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection())->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $questionId = $this->askedByOmarOfMia();
        self::getContainer()->get('doctrine')->resetManager();
        $asked = $this->stateOf($this->ticketId, self::ACME_FOODS);
        $otherBefore = $this->stateOf($otherTicketId, self::ACME_FOODS);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->answer()->answer(
            new OrganizationId(self::ACME_FOODS),
            $otherTicketId,
            $questionId,
            new UserId(self::MIA),
            self::ANSWER_BODY,
            new \DateTimeImmutable(self::ANSWERED_AT),
        )));

        self::assertSame([MessageNotFound::class, 'That message does not exist.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame($asked, $this->stateOf($this->ticketId, self::ACME_FOODS));
        self::assertSame($otherBefore, $this->stateOf($otherTicketId, self::ACME_FOODS));
    }

    private function askedByOmarOfMia(): string
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): PostedMessage => $this->askQuestion()->ask(
            $this->ticketId,
            $this->acmeTicketFacts(),
            new UserId(self::OMAR),
            new UserId(self::MIA),
            ' '.self::QUESTION_BODY."\n",
            new \DateTimeImmutable(self::ASKED_AT),
        ))->id;
    }

    private function acmeTicketFacts(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::FOR_EVERYONE, new TeamId(self::SHOP_TEAM), new UserId(self::MIA), new UserId(self::OMAR));
    }

    /**
     * AskQuestion as the container builds it, except that just before the pause locks the ticket a second session
     * gives the ticket to $newAssigneeId and commits, as a reassignment saved meanwhile would.
     */
    private function askQuestionWhileTheTicketIsGivenTo(string $newAssigneeId): AskQuestion
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        $reassignmentThenPause = new readonly class($this->ticketPauses(), $secondSession->entityManager->getConnection(), $newAssigneeId) implements TicketPauses {
            public function __construct(private TicketPauses $ticketPauses, private Connection $secondSession, private string $newAssigneeId)
            {
            }

            public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void
            {
                $this->secondSession->transactional(fn (Connection $connection): int|string => $connection->executeStatement(
                    'UPDATE tkt_ticket SET assignee_id = ?, version = version + 1 WHERE id = ?',
                    [$this->newAssigneeId, $ticketId->toString()],
                ));
                $this->ticketPauses->pauseForQuestion($ticketId, $askedUserId, $askerId);
            }

            public function endQuestionPause(TicketId $ticketId): void
            {
                $this->ticketPauses->endQuestionPause($ticketId);
            }
        };

        return new AskQuestion(
            new VisibleTickets($this->ticketVisibilities(), $this->tenantContext()),
            $reassignmentThenPause,
            $this->turnProjector(),
            new DoctrineMessages($this->entityManager()),
            new MessengerConversationEvents(self::getContainer()->get('event.bus'), $this->connection()),
            $this->pingClearing(),
        );
    }

    private function ticketPauses(): LockedTicketPauses
    {
        return new LockedTicketPauses(new DoctrineTickets($this->entityManager()), $this->ticketTurnSnapshots(), $this->tenantContext());
    }

    /**
     * Answer as the container builds it, except that its turn refresh writes the turn and then fails, as any failure
     * after the ticket resumed would.
     */
    private function answerThatFailsAfterTheTurnRefresh(): Answer
    {
        $messages = new DoctrineMessages($this->entityManager());

        return new Answer(
            new ThreadMessages($messages),
            $messages,
            new DoctrineReplies($this->entityManager()),
            $this->ticketPauses(),
            $this->turnProjectorThatFailsAfterTheRefresh(),
            new MessengerConversationEvents(self::getContainer()->get('event.bus'), $this->connection()),
            $this->pingClearing(),
        );
    }

    /**
     * WithdrawQuestion as the container builds it, except that its turn refresh writes the turn and then fails, as any
     * failure after the ticket resumed would.
     */
    private function withdrawQuestionThatFailsAfterTheTurnRefresh(): WithdrawQuestion
    {
        $messages = new DoctrineMessages($this->entityManager());

        return new WithdrawQuestion(
            new ThreadMessages($messages),
            $messages,
            $this->ticketPauses(),
            $this->turnProjectorThatFailsAfterTheRefresh(),
            new MessengerConversationEvents(self::getContainer()->get('event.bus'), $this->connection()),
        );
    }

    private function turnProjectorThatFailsAfterTheRefresh(): TurnProjector
    {
        return new readonly class($this->turnProjector()) implements TurnProjector {
            public function __construct(private TurnProjector $turnProjector)
            {
            }

            public function refresh(TicketId $ticketId): ?string
            {
                $this->turnProjector->refresh($ticketId);

                throw new \RuntimeException('The work after the turn refresh failed.');
            }

            public function refreshFrom(TicketTurnSnapshot $snapshot, ?array $waitingTurns = null): ?string
            {
                $this->turnProjector->refreshFrom($snapshot, $waitingTurns);

                throw new \RuntimeException('The work after the turn refresh failed.');
            }
        };
    }

    /**
     * Everything an ask, answer or withdrawal writes for the ticket.
     *
     * @return array{ticket: array{asked_user_id: mixed, asker_id: mixed, step_id: mixed, version: int}, messages: list<list<mixed>>, replies: list<list<mixed>>, turn: array<string, mixed>, conversation_events: list<string>}
     */
    private function stateOf(TicketId $ticketId, string $organizationId): array
    {
        return [
            'ticket' => $this->ticketStateOf($ticketId, $organizationId),
            'messages' => $this->messagesOf($ticketId, $organizationId),
            'replies' => $this->repliesOf($ticketId, $organizationId),
            'turn' => $this->turnOf($ticketId, $organizationId),
            'conversation_events' => $this->conversationEventsNaming($ticketId),
        ];
    }

    /**
     * @return array{asked_user_id: mixed, asker_id: mixed, step_id: mixed, version: int}
     */
    private function ticketStateOf(TicketId $ticketId, string $organizationId): array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchAssociative(
            'SELECT asked_user_id, asker_id, step_id, version FROM tkt_ticket WHERE id = ?',
            [$ticketId->toString()],
        ));
        self::assertIsArray($row);
        self::assertIsInt($row['version']);

        return ['asked_user_id' => $row['asked_user_id'], 'asker_id' => $row['asker_id'], 'step_id' => $row['step_id'], 'version' => $row['version']];
    }

    /**
     * @return list<list<mixed>> id, kind, author, person asked, body, created, resolved, resolved by and removed, oldest first
     */
    private function messagesOf(TicketId $ticketId, string $organizationId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT id, kind, author_id, to_user_id, body, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00'),
                    to_char(resolved_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00'), resolved_by,
                    to_char(removed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00')
             FROM cnv_message WHERE ticket_id = ? ORDER BY created_at, id",
            [$ticketId->toString()],
        ));
    }

    /**
     * @return list<list<mixed>> id, question, author, body and is_answer of the replies in the ticket's thread
     */
    private function repliesOf(TicketId $ticketId, string $organizationId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            'SELECT r.id, r.message_id, r.author_id, r.body, r.is_answer FROM cnv_reply r
             JOIN cnv_message m ON m.organization_id = r.organization_id AND m.id = r.message_id
             WHERE m.ticket_id = ? ORDER BY r.created_at, r.id',
            [$ticketId->toString()],
        ));
    }

    /**
     * @return array<string, mixed> the turn_user_id and kind of the ticket's turn row; empty when it has none
     */
    private function turnOf(TicketId $ticketId, string $organizationId): array
    {
        $turn = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchAssociative(
            'SELECT turn_user_id, kind FROM ins_ticket_turn WHERE ticket_id = ?',
            [$ticketId->toString()],
        ));

        return false === $turn ? [] : $turn;
    }

    /**
     * @return list<string> the short names of the Conversation events in the outbox that name the ticket, oldest first
     */
    private function conversationEventsNaming(TicketId $ticketId): array
    {
        $eventNames = [];
        foreach ($this->outboxBodiesNaming($ticketId->toString()) as $outboxBody) {
            if (1 === preg_match('/AppConversationApiEvent([A-Za-z]+)/', str_replace('\\', '', $outboxBody), $match)) {
                $eventNames[] = $match[1];
            }
        }

        return $eventNames;
    }

    /**
     * Only the outbox rows naming this test's ids: other tests delete outbox rows at the same time.
     *
     * @return list<string>
     */
    private function outboxBodiesNaming(string $id): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ? ORDER BY id", ['%'.$id.'%']) as $body) {
            self::assertIsString($body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    private function refusalOf(\Closure $work): \Throwable
    {
        try {
            $work();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('The action was not refused.');
    }

    private function askQuestion(): AskQuestion
    {
        $askQuestion = self::getContainer()->get('test.conversation_ask_question');
        self::assertInstanceOf(AskQuestion::class, $askQuestion);

        return $askQuestion;
    }

    private function answer(): Answer
    {
        $answer = self::getContainer()->get('test.conversation_answer');
        self::assertInstanceOf(Answer::class, $answer);

        return $answer;
    }

    private function pingClearing(): PingClearing
    {
        $pingClearing = self::getContainer()->get('test.shared_kernel_ping_clearing');
        self::assertInstanceOf(PingClearing::class, $pingClearing);

        return $pingClearing;
    }

    private function withdrawQuestion(): WithdrawQuestion
    {
        $withdrawQuestion = self::getContainer()->get('test.conversation_withdraw_question');
        self::assertInstanceOf(WithdrawQuestion::class, $withdrawQuestion);

        return $withdrawQuestion;
    }

    private function turnProjector(): TurnProjector
    {
        $turnProjector = self::getContainer()->get('test.insight_turn_projector');
        self::assertInstanceOf(TurnProjector::class, $turnProjector);

        return $turnProjector;
    }

    private function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        self::assertInstanceOf(TicketVisibilities::class, $ticketVisibilities);

        return $ticketVisibilities;
    }

    private function ticketTurnSnapshots(): TicketTurnSnapshots
    {
        $ticketTurnSnapshots = self::getContainer()->get('test.ticketing_ticket_turn_snapshots');
        self::assertInstanceOf(TicketTurnSnapshots::class, $ticketTurnSnapshots);

        return $ticketTurnSnapshots;
    }

    private function ticketCommands(): TicketCommands
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return $ticketCommands;
    }

    private function removeRows(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$organizationId.'%']);
        }
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ, self::SARA], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function threadRows(): ThreadRows
    {
        return new ThreadRows($this->migrator(), $this->tenantContext());
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
