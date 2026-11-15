<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\EditMessage;

use App\Conversation\Api\Event\MessageEdited;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageEditRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Application\EditMessage\EditMessage;
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

final class EditMessageTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e101';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e102';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e111';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e112';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000e121';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-00000000e122';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000e1ff';
    private const string POSTED_AT = '2027-03-15 10:00:00+00:00';

    private InMemoryMessages $messages;
    private RecordingConversationEvents $conversationEvents;
    private EditMessage $editMessage;

    protected function setUp(): void
    {
        $this->messages = new InMemoryMessages();
        $this->messages->add(Message::comment(new MessageId(self::LENAS_COMMENT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), 'The button moves.', new \DateTimeImmutable(self::POSTED_AT)));
        $this->messages->add(Message::question(new MessageId(self::OPEN_QUESTION), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which build?', new \DateTimeImmutable(self::POSTED_AT)));
        $this->conversationEvents = new RecordingConversationEvents();
        $this->editMessage = new EditMessage(new ThreadMessages($this->messages), $this->messages, $this->conversationEvents);
    }

    #[Test]
    public function anEditSavesTheCommentAndPublishesMessageEditedWithItsIdsTheEditorAndTheTime(): void
    {
        $editedAt = new \DateTimeImmutable('2027-03-15 10:07:00+00:00');

        $this->editMessage->edit(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::LENAS_COMMENT, new UserId(self::LENA), 'The button hides.', $editedAt);

        self::assertEquals([new MessageEdited(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, $editedAt)], $this->conversationEvents->published());
        self::assertSame([self::LENAS_COMMENT], $this->messages->savedIds());
    }

    /**
     * @return iterable<string, array{string, string, string, string, class-string<\Throwable>, string}>
     */
    public static function refusedEdits(): iterable
    {
        yield 'someone else\'s comment' => [self::LENAS_COMMENT, self::OMAR, 'Changed.', '2027-03-15 10:05:00+00:00', MessageEditRefused::class, 'Only the person who wrote a message can edit it.'];
        yield 'one second after the 15 minutes' => [self::LENAS_COMMENT, self::LENA, 'Changed.', '2027-03-15 10:15:01+00:00', MessageEditRefused::class, 'You can edit a message for 15 minutes after you post it.'];
        yield 'a blank body' => [self::LENAS_COMMENT, self::LENA, " \t\n", '2027-03-15 10:05:00+00:00', MessageBodyRefused::class, 'A message needs a body.'];
        yield 'a question' => [self::OPEN_QUESTION, self::LENA, 'Changed.', '2027-03-15 10:05:00+00:00', MessageEditRefused::class, 'A question cannot be edited.'];
        yield 'an unknown message' => [self::UNKNOWN_MESSAGE, self::LENA, 'Changed.', '2027-03-15 10:05:00+00:00', MessageNotFound::class, 'That message does not exist.'];
    }

    /**
     * @param class-string<\Throwable> $refusalClass
     */
    #[Test]
    #[DataProvider('refusedEdits')]
    public function aRefusedEditPublishesNothingAndSavesNothing(string $messageId, string $editorId, string $body, string $editedAt, string $refusalClass, string $reason): void
    {
        try {
            $this->editMessage->edit(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $messageId, new UserId($editorId), $body, new \DateTimeImmutable($editedAt));
            self::fail('The edit was made.');
        } catch (MessageEditRefused|MessageBodyRefused|MessageNotFound $refusal) {
            self::assertSame([$refusalClass, $reason], [$refusal::class, $refusal->getMessage()]);
        }

        self::assertSame([], $this->conversationEvents->published());
        self::assertSame([], $this->messages->savedIds());
    }
}
