<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\PostReply;

use App\Conversation\Api\Event\ReplyPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ReplyRefused;
use App\Conversation\Application\PostedMessage;
use App\Conversation\Application\PostReply\PostReply;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Application\VisibleTickets;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\Reply;
use App\Conversation\Domain\ReplyId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Conversation\Application\InMemoryMessages;
use App\Tests\Unit\Conversation\Application\InMemoryPingClearing;
use App\Tests\Unit\Conversation\Application\InMemoryReplies;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostReplyTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e502';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e503';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e504';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000e505';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e511';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e512';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e513';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000e521';
    private const string OPEN_QUESTION_TO_OMAR = '0197a3c0-0000-7000-8000-00000000e522';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-00000000e523';
    private const string WITHDRAWN_QUESTION = '0197a3c0-0000-7000-8000-00000000e524';
    private const string OTHER_TICKETS_COMMENT = '0197a3c0-0000-7000-8000-00000000e525';
    private const string OMARS_REPLY = '0197a3c0-0000-7000-8000-00000000e531';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000e5ff';
    private const string POSTED_AT = '2027-03-15 10:00:00+00:00';
    private const string REPLIED_AT = '2027-03-15 10:20:00+00:00';

    private InMemoryMessages $messages;
    private InMemoryReplies $replies;
    private RecordingConversationEvents $conversationEvents;
    private TenantContext $tenantContext;
    private InMemoryPingClearing $pingClearing;
    private PostReply $postReply;

    protected function setUp(): void
    {
        $postedAt = new \DateTimeImmutable(self::POSTED_AT);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $ticket = new TicketId(self::TICKET);
        $this->messages = new InMemoryMessages();
        $this->messages->add(Message::comment(new MessageId(self::LENAS_COMMENT), $acmeFoods, $ticket, new UserId(self::LENA), 'The button moves.', $postedAt));
        $this->messages->add(Message::question(new MessageId(self::OPEN_QUESTION_TO_OMAR), $acmeFoods, $ticket, new UserId(self::LENA), new UserId(self::OMAR), 'Which build?', $postedAt));
        $removedComment = Message::comment(new MessageId(self::REMOVED_COMMENT), $acmeFoods, $ticket, new UserId(self::LENA), 'Wrong ticket.', $postedAt);
        $removedComment->remove(new UserId(self::LENA), $postedAt->modify('+2 minutes'));
        $this->messages->add($removedComment);
        $withdrawnQuestion = Message::question(new MessageId(self::WITHDRAWN_QUESTION), $acmeFoods, new TicketId(self::OTHER_TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Which phone?', $postedAt);
        $withdrawnQuestion->withdraw(new UserId(self::LENA), $postedAt->modify('+3 minutes'));
        $this->messages->add($withdrawnQuestion);
        $this->messages->add(Message::comment(new MessageId(self::OTHER_TICKETS_COMMENT), $acmeFoods, new TicketId(self::OTHER_TICKET), new UserId(self::LENA), 'Seen on build 42.', $postedAt));
        $this->replies = new InMemoryReplies();
        $this->replies->add(Reply::post(new ReplyId(self::OMARS_REPLY), $acmeFoods, new MessageId(self::LENAS_COMMENT), new UserId(self::OMAR), 'Me too.', false, $postedAt->modify('+5 minutes')));
        $this->conversationEvents = new RecordingConversationEvents();
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set($acmeFoods);
        $this->pingClearing = new InMemoryPingClearing();
        $this->postReply = new PostReply(new VisibleTickets(self::visibilitiesWithShopTeam([self::LENA, self::OMAR]), $this->tenantContext), new ThreadMessages($this->messages), $this->replies, $this->conversationEvents, $this->pingClearing);
    }

    #[Test]
    public function aReplyClearsTheAuthorsPingsOnTheTicketAndSaysHowMany(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::OMAR), 2);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::OTHER_TICKET), new UserId(self::OMAR), 1);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::LENA), 1);
        $repliedAt = new \DateTimeImmutable(self::REPLIED_AT);

        $reply = $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::OMAR), 'Seen on build 42 too.', $repliedAt);

        self::assertSame(2, $reply->clearedPingCount);
        self::assertEquals([[self::ACME_FOODS, self::TICKET, self::OMAR, $repliedAt]], $this->pingClearing->calls());
    }

    #[Test]
    public function aReplyBySomeoneWithNoOpenPingClearsNone(): void
    {
        $this->pingClearing->addOpenPings(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), 2);

        $reply = $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::LENA), 'Fixed in build 43.', new \DateTimeImmutable(self::REPLIED_AT));

        self::assertSame(0, $reply->clearedPingCount);
    }

    #[Test]
    public function aReplyIsAddedTrimmedUnderTheMessageAndPublishesReplyPostedForTheMessagesAuthor(): void
    {
        $repliedAt = new \DateTimeImmutable(self::REPLIED_AT);

        $replyId = $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::OMAR), "  Seen on build 42 too.\n", $repliedAt)->id;

        $newReply = $this->replies->added()[1] ?? null;
        self::assertNotNull($newReply, 'No reply was added.');
        self::assertSame(
            [$replyId, self::ACME_FOODS, self::LENAS_COMMENT, self::OMAR, 'Seen on build 42 too.', false, $repliedAt],
            [$newReply->id()->toString(), $newReply->organizationId()->toString(), $newReply->messageId()->toString(), $newReply->authorId()->toString(), $newReply->body(), $newReply->isAnswer(), $newReply->createdAt()],
        );
        self::assertEquals(
            [new ReplyPosted(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, $replyId, self::LENA, self::OMAR, $repliedAt)],
            $this->conversationEvents->published(),
        );
    }

    #[Test]
    public function aReplyOfTheMaximumLengthIsAdded(): void
    {
        $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::OMAR), str_repeat('é', Reply::MAX_BODY_LENGTH), new \DateTimeImmutable(self::REPLIED_AT));

        self::assertCount(2, $this->replies->added());
    }

    #[Test]
    public function aReplyToOnesOwnMessageIsPublishedWithTheReplierAsTheMessagesAuthor(): void
    {
        $replyId = $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::LENA), 'Fixed in build 43.', new \DateTimeImmutable(self::REPLIED_AT))->id;

        self::assertEquals(
            [new ReplyPosted(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, $replyId, self::LENA, self::LENA, new \DateTimeImmutable(self::REPLIED_AT))],
            $this->conversationEvents->published(),
        );
    }

    #[Test]
    public function aReplyByThePersonAskedIsAPlainReplyAndLeavesTheQuestionOpen(): void
    {
        $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::OPEN_QUESTION_TO_OMAR, new UserId(self::OMAR), 'Let me check.', new \DateTimeImmutable(self::REPLIED_AT));

        $newReply = $this->replies->added()[1] ?? null;
        self::assertNotNull($newReply, 'No reply was added.');
        self::assertFalse($newReply->isAnswer());
        self::assertNull($this->messages->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId(self::OPEN_QUESTION_TO_OMAR))?->resolvedAt());
        self::assertSame([], $this->messages->savedIds());
        self::assertSame([ReplyPosted::class], array_map(static fn (object $event): string => $event::class, $this->conversationEvents->published()));
    }

    #[Test]
    public function twoRepliesGetDifferentIdsAndStayInTheOrderPosted(): void
    {
        $firstId = $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::OMAR), 'First.', new \DateTimeImmutable(self::REPLIED_AT))->id;
        $secondId = $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::LENA), 'Second.', new \DateTimeImmutable(self::REPLIED_AT))->id;

        self::assertNotSame($firstId, $secondId);
        self::assertSame(
            [self::OMARS_REPLY, $firstId, $secondId],
            array_map(static fn (Reply $reply): string => $reply->id()->toString(), $this->replies->added()),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, string, class-string<\Throwable>, string}>
     */
    public static function refusedReplies(): iterable
    {
        yield 'a reply to a reply' => [self::TICKET, self::OMARS_REPLY, self::LENA, 'Thanks.', MessageNotFound::class, 'That message does not exist.'];
        yield 'an unknown message' => [self::TICKET, self::UNKNOWN_MESSAGE, self::LENA, 'Thanks.', MessageNotFound::class, 'That message does not exist.'];
        yield 'a malformed message id' => [self::TICKET, 'not-a-message-id', self::LENA, 'Thanks.', MessageNotFound::class, 'That message does not exist.'];
        yield 'another ticket\'s message' => [self::TICKET, self::OTHER_TICKETS_COMMENT, self::LENA, 'Thanks.', MessageNotFound::class, 'That message does not exist.'];
        yield 'a removed comment' => [self::TICKET, self::REMOVED_COMMENT, self::OMAR, 'Thanks.', ReplyRefused::class, 'This message was removed, so you cannot reply to it.'];
        yield 'a withdrawn question' => [self::OTHER_TICKET, self::WITHDRAWN_QUESTION, self::OMAR, 'Thanks.', ReplyRefused::class, 'This message was removed, so you cannot reply to it.'];
        yield 'a blank body' => [self::TICKET, self::LENAS_COMMENT, self::OMAR, " \t\n", MessageBodyRefused::class, 'A reply needs a body.'];
        yield 'one character over the limit' => [self::TICKET, self::LENAS_COMMENT, self::OMAR, str_repeat('é', Reply::MAX_BODY_LENGTH + 1), MessageBodyRefused::class, 'A reply can have at most 10000 characters.'];
        yield 'someone outside the ticket\'s team' => [self::TICKET, self::LENAS_COMMENT, self::MIA, 'Thanks.', TicketNotFound::class, 'That ticket does not exist.'];
    }

    /**
     * @param class-string<\Throwable> $refusalClass
     */
    #[Test]
    #[DataProvider('refusedReplies')]
    public function aRefusedReplyAddsNothingPublishesNothingAndLeavesTheMessage(string $ticketId, string $messageId, string $authorId, string $body, string $refusalClass, string $reason): void
    {
        $refusal = $this->refusalOf(fn (): PostedMessage => $this->postReply->post(new TicketId($ticketId), self::teamOnlyTicket(), $messageId, new UserId($authorId), $body, new \DateTimeImmutable(self::REPLIED_AT)));

        self::assertSame([$refusalClass, $reason], [$refusal::class, $refusal->getMessage()]);
        $this->assertNothingChanged();
    }

    #[Test]
    public function aTicketOfAnotherOrganizationThanTheTenantIsNotFoundThoughItIsForEveryone(): void
    {
        $betaTicket = new TicketVisibilityFacts(new OrganizationId(self::BETA_BAKERY), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA));

        $refusal = $this->refusalOf(fn (): PostedMessage => $this->postReply->post(new TicketId(self::TICKET), $betaTicket, self::LENAS_COMMENT, new UserId(self::LENA), 'Thanks.', new \DateTimeImmutable(self::REPLIED_AT)));

        self::assertSame([TicketNotFound::class, 'That ticket does not exist.'], [$refusal::class, $refusal->getMessage()]);
        $this->assertNothingChanged();
    }

    #[Test]
    public function noTenantIsNotFound(): void
    {
        $this->tenantContext->clear();

        $refusal = $this->refusalOf(fn (): PostedMessage => $this->postReply->post(new TicketId(self::TICKET), self::teamOnlyTicket(), self::LENAS_COMMENT, new UserId(self::LENA), 'Thanks.', new \DateTimeImmutable(self::REPLIED_AT)));

        self::assertSame([TicketNotFound::class, 'That ticket does not exist.'], [$refusal::class, $refusal->getMessage()]);
        $this->assertNothingChanged();
    }

    private function assertNothingChanged(): void
    {
        self::assertSame([self::OMARS_REPLY], array_map(static fn (Reply $reply): string => $reply->id()->toString(), $this->replies->added()));
        self::assertSame([], $this->conversationEvents->published());
        self::assertSame([], $this->messages->savedIds());
        self::assertSame([], $this->pingClearing->calls());
    }

    /**
     * @param \Closure(): PostedMessage $post
     */
    private function refusalOf(\Closure $post): \Throwable
    {
        try {
            $post();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('The reply was posted.');
    }

    private static function teamOnlyTicket(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::LENA));
    }

    /**
     * @param list<string> $teamMemberIds the people in the Shop team; nobody is an admin or the triager
     */
    private static function visibilitiesWithShopTeam(array $teamMemberIds): TicketVisibilities
    {
        return new readonly class(new TeamId(self::SHOP_TEAM), $teamMemberIds) implements TicketVisibilities {
            /**
             * @param list<string> $teamMemberIds
             */
            public function __construct(private TeamId $shopTeamId, private array $teamMemberIds)
            {
            }

            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                $teamIds = \in_array($viewerId->toString(), $this->teamMemberIds, true) ? [$this->shopTeamId] : [];

                return TicketVisibility::for($organizationId, $viewerId, $teamIds, false, null);
            }
        };
    }
}
