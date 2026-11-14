<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\RemoveMessage;

use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\MessageRemovalRefused;
use App\Conversation\Application\RemoveMessage\RemoveMessage;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Conversation\Application\InMemoryMessages;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RemoveMessageTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e201';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e202';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000e203';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e211';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e212';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000e221';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-00000000e222';
    private const string POSTED_AT = '2027-03-15 10:00:00+00:00';

    private InMemoryMessages $messages;
    private RecordingConversationEvents $conversationEvents;
    private RemoveMessage $removeMessage;

    protected function setUp(): void
    {
        $this->messages = new InMemoryMessages();
        $this->messages->add(Message::comment(new MessageId(self::LENAS_COMMENT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), 'The button moves.', new \DateTimeImmutable(self::POSTED_AT)));
        $this->messages->add(Message::question(new MessageId(self::OPEN_QUESTION), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which build?', new \DateTimeImmutable(self::POSTED_AT)));
        $this->conversationEvents = new RecordingConversationEvents();
        $this->removeMessage = new RemoveMessage(new ThreadMessages($this->messages), $this->messages, $this->conversationEvents);
    }

    #[Test]
    public function aRemovalADayLaterSavesTheCommentAndPublishesMessageRemovedWithItsIdsTheRemoverAndTheTime(): void
    {
        $removedAt = new \DateTimeImmutable('2027-03-16 09:30:00+00:00');

        $this->removeMessage->remove(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::LENAS_COMMENT, new UserId(self::LENA), $removedAt);

        self::assertEquals([new MessageRemoved(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, $removedAt)], $this->conversationEvents->published());
        self::assertSame([self::LENAS_COMMENT], $this->messages->savedIds());
    }

    #[Test]
    public function aSecondRemovalOfTheSameCommentIsRefusedAndPublishesNothingMore(): void
    {
        $removedAt = new \DateTimeImmutable('2027-03-15 10:05:00+00:00');
        $this->removeMessage->remove(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::LENAS_COMMENT, new UserId(self::LENA), $removedAt);

        try {
            $this->removeMessage->remove(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::LENAS_COMMENT, new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:06:00+00:00'));
            self::fail('The comment was removed twice.');
        } catch (MessageRemovalRefused $refusal) {
            self::assertSame('This message is already removed.', $refusal->getMessage());
        }

        self::assertEquals([new MessageRemoved(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, $removedAt)], $this->conversationEvents->published());
        self::assertSame([self::LENAS_COMMENT], $this->messages->savedIds());
    }

    /**
     * @return iterable<string, array{string, string, string, class-string<\Throwable>, string}>
     */
    public static function refusedRemovals(): iterable
    {
        yield 'someone else\'s comment' => [self::TICKET, self::LENAS_COMMENT, self::OMAR, MessageRemovalRefused::class, 'Only the person who wrote a message can remove it.'];
        yield 'a question' => [self::TICKET, self::OPEN_QUESTION, self::LENA, MessageRemovalRefused::class, 'A question cannot be removed.'];
        yield 'another ticket\'s comment' => [self::OTHER_TICKET, self::LENAS_COMMENT, self::LENA, MessageNotFound::class, 'That message does not exist.'];
        yield 'a malformed id' => [self::TICKET, 'not-a-message-id', self::LENA, MessageNotFound::class, 'That message does not exist.'];
    }

    /**
     * @param class-string<\Throwable> $refusalClass
     */
    #[Test]
    #[DataProvider('refusedRemovals')]
    public function aRefusedRemovalPublishesNothingAndSavesNothing(string $ticketId, string $messageId, string $removerId, string $refusalClass, string $reason): void
    {
        try {
            $this->removeMessage->remove(new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), $messageId, new UserId($removerId), new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
            self::fail('The comment was removed.');
        } catch (MessageRemovalRefused|MessageNotFound $refusal) {
            self::assertSame([$refusalClass, $reason], [$refusal::class, $refusal->getMessage()]);
        }

        self::assertSame([], $this->conversationEvents->published());
        self::assertSame([], $this->messages->savedIds());
    }
}
