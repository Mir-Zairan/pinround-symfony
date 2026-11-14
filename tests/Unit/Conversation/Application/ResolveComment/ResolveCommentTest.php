<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\ResolveComment;

use App\Conversation\Api\Event\CommentResolved;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ResolveRefused;
use App\Conversation\Application\ResolveComment\ResolveComment;
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

final class ResolveCommentTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e301';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e302';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000e303';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e311';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e312';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e313';
    private const string OMARS_COMMENT = '0197a3c0-0000-7000-8000-00000000e321';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-00000000e322';
    private const string RESOLVED_COMMENT = '0197a3c0-0000-7000-8000-00000000e323';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-00000000e324';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000e3ff';
    private const string POSTED_AT = '2027-03-15 10:00:00+00:00';
    private const string RESOLVED_AT = '2027-03-15 11:30:00+00:00';

    private InMemoryMessages $messages;
    private RecordingConversationEvents $conversationEvents;
    private ResolveComment $resolveComment;

    protected function setUp(): void
    {
        $this->messages = new InMemoryMessages();
        $this->messages->add($this->commentOf(self::OMARS_COMMENT));
        $removedComment = $this->commentOf(self::REMOVED_COMMENT);
        $removedComment->remove(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:02:00+00:00'));
        $this->messages->add($removedComment);
        $resolvedComment = $this->commentOf(self::RESOLVED_COMMENT);
        $resolvedComment->resolve(new UserId(self::OMAR), false, new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
        $this->messages->add($resolvedComment);
        $this->messages->add(Message::question(new MessageId(self::OPEN_QUESTION), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), new UserId(self::MIA), 'Which build?', new \DateTimeImmutable(self::POSTED_AT)));
        $this->conversationEvents = new RecordingConversationEvents();
        $this->resolveComment = new ResolveComment(new ThreadMessages($this->messages), $this->messages, $this->conversationEvents);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function resolvers(): iterable
    {
        yield 'the author, on a team with a lead' => [self::OMAR, self::LENA];
        yield 'the author, on a ticket with no team lead' => [self::OMAR, null];
        yield 'the lead of the ticket\'s team' => [self::LENA, self::LENA];
    }

    #[Test]
    #[DataProvider('resolvers')]
    public function aResolveSavesTheCommentResolvedByTheResolverAndPublishesCommentResolved(string $resolverId, ?string $teamLeadId): void
    {
        $resolvedAt = new \DateTimeImmutable(self::RESOLVED_AT);

        $this->resolveComment->resolve(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::OMARS_COMMENT, new UserId($resolverId), null === $teamLeadId ? null : new UserId($teamLeadId), $resolvedAt);

        $comment = $this->messages->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId(self::OMARS_COMMENT));
        self::assertNotNull($comment);
        self::assertEquals([$resolvedAt, new UserId($resolverId)], [$comment->resolvedAt(), $comment->resolvedBy()]);
        self::assertEquals([new CommentResolved(self::ACME_FOODS, self::TICKET, self::OMARS_COMMENT, $resolverId, $resolvedAt)], $this->conversationEvents->published());
        self::assertSame([self::OMARS_COMMENT], $this->messages->savedIds());
    }

    /**
     * @return iterable<string, array{string, string, string|null, string, class-string<\Throwable>, string}>
     */
    public static function refusedResolves(): iterable
    {
        yield 'a question, even by its asker' => [self::OPEN_QUESTION, self::OMAR, self::LENA, self::TICKET, ResolveRefused::class, 'A question is closed by its answer, not by Resolve.'];
        yield 'a removed comment' => [self::REMOVED_COMMENT, self::OMAR, self::LENA, self::TICKET, ResolveRefused::class, 'This comment was removed, so it cannot be resolved.'];
        yield 'an already resolved comment' => [self::RESOLVED_COMMENT, self::LENA, self::LENA, self::TICKET, ResolveRefused::class, 'This comment is already resolved.'];
        yield 'someone else\'s comment, by a member' => [self::OMARS_COMMENT, self::MIA, self::LENA, self::TICKET, ResolveRefused::class, 'Only the person who wrote a comment or the lead of the ticket\'s team can resolve it.'];
        yield 'someone else\'s comment, on a ticket with no team lead' => [self::OMARS_COMMENT, self::LENA, null, self::TICKET, ResolveRefused::class, 'Only the person who wrote a comment or the lead of the ticket\'s team can resolve it.'];
        yield 'an unknown message' => [self::UNKNOWN_MESSAGE, self::OMAR, self::LENA, self::TICKET, MessageNotFound::class, 'That message does not exist.'];
        yield 'a malformed message id' => ['not-a-message-id', self::OMAR, self::LENA, self::TICKET, MessageNotFound::class, 'That message does not exist.'];
        yield 'another ticket\'s comment' => [self::OMARS_COMMENT, self::OMAR, self::LENA, self::OTHER_TICKET, MessageNotFound::class, 'That message does not exist.'];
    }

    /**
     * @param class-string<\Throwable> $refusalClass
     */
    #[Test]
    #[DataProvider('refusedResolves')]
    public function aRefusedResolvePublishesNothingSavesNothingAndLeavesTheMessageAsItWas(string $messageId, string $resolverId, ?string $teamLeadId, string $ticketId, string $refusalClass, string $reason): void
    {
        $before = $this->resolvedStateOfAll();

        try {
            $this->resolveComment->resolve(new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), $messageId, new UserId($resolverId), null === $teamLeadId ? null : new UserId($teamLeadId), new \DateTimeImmutable(self::RESOLVED_AT));
            self::fail('The comment was resolved.');
        } catch (ResolveRefused|MessageNotFound $refusal) {
            self::assertSame([$refusalClass, $reason], [$refusal::class, $refusal->getMessage()]);
        }

        self::assertSame([], $this->conversationEvents->published());
        self::assertSame([], $this->messages->savedIds());
        self::assertEquals($before, $this->resolvedStateOfAll());
    }

    private function commentOf(string $messageId): Message
    {
        return Message::comment(new MessageId($messageId), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), 'The button moves.', new \DateTimeImmutable(self::POSTED_AT));
    }

    /**
     * @return array<string, array{\DateTimeImmutable|null, UserId|null}> resolvedAt and resolvedBy by message id
     */
    private function resolvedStateOfAll(): array
    {
        $states = [];
        foreach ([self::OMARS_COMMENT, self::REMOVED_COMMENT, self::RESOLVED_COMMENT, self::OPEN_QUESTION] as $messageId) {
            $message = $this->messages->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId($messageId));
            self::assertNotNull($message);
            $states[$messageId] = [$message->resolvedAt(), $message->resolvedBy()];
        }

        return $states;
    }
}
