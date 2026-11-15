<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\Answer;

use App\Conversation\Api\QuestionRefused;
use App\Conversation\Application\Answer\Answer;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Conversation\Application\InMemoryMessages;
use App\Tests\Unit\Conversation\Application\InMemoryPingClearing;
use App\Tests\Unit\Conversation\Application\InMemoryReplies;
use App\Tests\Unit\Conversation\Application\InMemoryTicketPauses;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use App\Tests\Unit\Conversation\Application\TurnProjectorWithoutNotes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What Answer hands to PingClearing (FR-PNG-05); the closed question, the resumed ticket and the turn are covered by
 * QuestionUseCasesTest.
 */
final class AnswerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e801';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e804';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000e805';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e811';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e812';
    private const string OPEN_QUESTION_TO_OMAR = '0197a3c0-0000-7000-8000-00000000e821';
    private const string WITHDRAWN_QUESTION_TO_OMAR = '0197a3c0-0000-7000-8000-00000000e822';
    private const string ASKED_AT = '2027-03-15 10:00:00+00:00';
    private const string ANSWERED_AT = '2027-03-15 10:20:00+00:00';

    private InMemoryPingClearing $pingClearing;
    private InMemoryTicketPauses $ticketPauses;
    private InMemoryMessages $messages;
    private InMemoryReplies $replies;
    private RecordingConversationEvents $conversationEvents;
    private Answer $answer;

    protected function setUp(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $askedAt = new \DateTimeImmutable(self::ASKED_AT);
        $messages = new InMemoryMessages();
        $this->messages = $messages;
        $withdrawnQuestion = Message::question(new MessageId(self::WITHDRAWN_QUESTION_TO_OMAR), $acmeFoods, new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which phone?', $askedAt);
        $withdrawnQuestion->withdraw(new UserId(self::LENA), $askedAt->modify('+1 minute'));
        $messages->add($withdrawnQuestion);
        $messages->add(Message::question(new MessageId(self::OPEN_QUESTION_TO_OMAR), $acmeFoods, new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which build?', $askedAt->modify('+2 minutes')));
        $this->pingClearing = new InMemoryPingClearing();
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::OMAR), 2);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::OTHER_TICKET), new UserId(self::OMAR), 4);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::LENA), 1);
        $this->replies = new InMemoryReplies();
        $this->ticketPauses = new InMemoryTicketPauses([self::TICKET, self::OTHER_TICKET], [self::TICKET]);
        $this->conversationEvents = new RecordingConversationEvents();
        $this->answer = new Answer(
            new ThreadMessages($messages),
            $messages,
            $this->replies,
            $this->ticketPauses,
            new TurnProjectorWithoutNotes(),
            $this->conversationEvents,
            $this->pingClearing,
        );
    }

    #[Test]
    public function anAnswerClearsTheAnswerersPingsOnTheTicketAtTheAnswerTimeAndSaysHowMany(): void
    {
        $answeredAt = new \DateTimeImmutable(self::ANSWERED_AT);

        $answerReply = $this->answer->answer(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::OPEN_QUESTION_TO_OMAR, new UserId(self::OMAR), 'Build 42.', $answeredAt);

        self::assertSame(2, $answerReply->clearedPingCount);
        self::assertEquals([[self::ACME_FOODS, self::TICKET, self::OMAR, $answeredAt]], $this->pingClearing->calls());
        self::assertSame([], $this->ticketPauses->pausedTicketIds(), 'the answer ends the pause');
    }

    #[Test]
    public function anAnswerToAWithdrawnQuestionClearsNothing(): void
    {
        try {
            $this->answer->answer(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::WITHDRAWN_QUESTION_TO_OMAR, new UserId(self::OMAR), 'Build 42.', new \DateTimeImmutable(self::ANSWERED_AT));
            self::fail('The withdrawn question was answered.');
        } catch (QuestionRefused $refusal) {
            self::assertSame('This question was withdrawn.', $refusal->getMessage());
        }

        self::assertSame([], $this->pingClearing->calls());
        self::assertSame([], $this->replies->added());
        self::assertSame([], $this->messages->savedIds());
        self::assertSame([], $this->conversationEvents->published());
        self::assertSame([self::TICKET], $this->ticketPauses->pausedTicketIds(), 'the open question still pauses the ticket');
    }
}
