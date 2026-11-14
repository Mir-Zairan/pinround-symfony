<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\Event\ReplyPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ReplyRefused;
use App\Conversation\Application\PostReply\PostReply;
use App\Conversation\Application\RemoveMessage\RemoveMessage;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Application\VisibleTickets;
use App\Conversation\Infrastructure\Doctrine\DoctrineMessages;
use App\Conversation\Infrastructure\Doctrine\DoctrineReplies;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PostReply on the real tables, under comments posted at 10:00 on a fixed day. A refusal is caught inside a transaction
 * that then commits, so "nothing written" proves the check comes before the write, not that a rollback undid it.
 */
final class PostReplyTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000ca01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000ca02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000ca03';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000ca04';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000ca05';
    private const string TICKET_FOR_EVERYONE = '0197a3c0-0000-7000-8000-00000000ca06';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-00000000ca07';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-00000000ca08';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000ca11';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000ca12';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000ca13';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000ca21';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-00000000ca22';
    private const string OPEN_QUESTION_TO_OMAR = '0197a3c0-0000-7000-8000-00000000ca23';
    private const string TEAM_ONLY_COMMENT = '0197a3c0-0000-7000-8000-00000000ca24';
    private const string BETA_COMMENT = '0197a3c0-0000-7000-8000-00000000ca25';
    private const string OMARS_REPLY = '0197a3c0-0000-7000-8000-00000000ca31';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000caff';
    private const string REPLIED_AT = '2027-03-15 10:20:00+00:00';

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->deleteOutboxRowsOfTheseTickets();
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::LENA, '{}');
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::OMAR, '{}');
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::MIA, '{}');
        $this->threadRows()->addMembershipOf(self::BETA_BAKERY, self::LENA, '{}');
        $this->threadRows()->addTeamOf(self::ACME_FOODS, self::SHOP_TEAM, self::LENA, [self::OMAR]);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE, self::SHOP_PROJECT, 1, 'all', null, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::SHOP_PROJECT, 2, 'team', self::SHOP_TEAM, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, 1, 'all', null, self::LENA);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE, self::LENAS_COMMENT, self::LENA, 'The button moves on small screens.', '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE, self::REMOVED_COMMENT, self::LENA, 'Wrong ticket.', '2027-03-15 10:00:00+00', '2027-03-15 10:02:00+00');
        $this->threadRows()->addOpenQuestionOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE, self::OPEN_QUESTION_TO_OMAR, self::LENA, self::OMAR, 'Which build?', '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::TEAM_ONLY_COMMENT, self::LENA, 'Only the team sees this.', '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_COMMENT, self::LENA, 'The oven is late.', '2027-03-15 10:00:00+00');
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at) VALUES (?, ?, ?, ?, 'Me too.', false, '2027-03-15 10:05:00+00')",
                [self::ACME_FOODS, self::OMARS_REPLY, self::LENAS_COMMENT, self::OMAR],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->deleteOutboxRowsOfTheseTickets();
        parent::tearDown();
    }

    #[Test]
    public function aReplyIsSavedTrimmedUnderTheMessageWithOneReplyPostedForTheMessagesAuthorInTheOutbox(): void
    {
        $replyId = $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->postReply()->post(
            new TicketId(self::TICKET_FOR_EVERYONE),
            $this->factsOfTicketForEveryone(),
            self::LENAS_COMMENT,
            new UserId(self::MIA),
            "  Seen on build 42 too.\n",
            new \DateTimeImmutable(self::REPLIED_AT),
        ));

        self::assertSame(
            [
                [self::ACME_FOODS, self::OMARS_REPLY, self::OMAR, 'Me too.', false, '2027-03-15 10:05:00+00'],
                [self::ACME_FOODS, $replyId, self::MIA, 'Seen on build 42 too.', false, '2027-03-15 10:20:00+00'],
            ],
            $this->replyRowsOf(self::ACME_FOODS, self::LENAS_COMMENT),
        );
        $outboxBodies = $this->outboxBodiesNaming($replyId);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', ReplyPosted::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringContainsString(self::LENAS_COMMENT, $outboxBodies[0]);
        self::assertStringContainsString(self::LENA, $outboxBodies[0]);
        self::assertStringContainsString(self::MIA, $outboxBodies[0]);
    }

    #[Test]
    public function aReplyByThePersonAskedIsAPlainReplyAndTheQuestionStaysOpen(): void
    {
        $replyId = $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->postReply()->post(
            new TicketId(self::TICKET_FOR_EVERYONE),
            $this->factsOfTicketForEveryone(),
            self::OPEN_QUESTION_TO_OMAR,
            new UserId(self::OMAR),
            'Let me check.',
            new \DateTimeImmutable(self::REPLIED_AT),
        ));

        self::assertSame(
            [[self::ACME_FOODS, $replyId, self::OMAR, 'Let me check.', false, '2027-03-15 10:20:00+00']],
            $this->replyRowsOf(self::ACME_FOODS, self::OPEN_QUESTION_TO_OMAR),
        );
        self::assertSame([null, null], $this->resolutionOf(self::ACME_FOODS, self::OPEN_QUESTION_TO_OMAR));
    }

    #[Test]
    public function aReplysIdPostedAsTheMessageIsNotFoundAndNoReplyIsWritten(): void
    {
        $refusal = $this->refusalOfReply(self::OMARS_REPLY, self::LENA);

        self::assertSame([MessageNotFound::class, 'That message does not exist.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame([[self::OMARS_REPLY], 0], [$this->replyIdsOf(self::ACME_FOODS), \count($this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE))]);
    }

    #[Test]
    public function anUnknownMalformedOrOtherTicketsMessageIdIsNotFoundAndNoReplyIsWritten(): void
    {
        $refusals = [];
        foreach ([[self::TICKET_FOR_EVERYONE, self::UNKNOWN_MESSAGE], [self::TICKET_FOR_EVERYONE, 'not-a-message-id'], [self::TICKET_FOR_EVERYONE, self::TEAM_ONLY_COMMENT]] as [$ticketId, $messageId]) {
            $refusal = $this->refusalOfReply($messageId, self::LENA, 'Thanks.', $ticketId);
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 3, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame([[self::OMARS_REPLY], 0], [$this->replyIdsOf(self::ACME_FOODS), \count($this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE))]);
    }

    #[Test]
    public function aReplyToARemovedCommentIsRefusedWithItsReasonAndNothingIsWritten(): void
    {
        $refusal = $this->refusalOfReply(self::REMOVED_COMMENT, self::OMAR);

        self::assertSame([ReplyRefused::class, 'This message was removed, so you cannot reply to it.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame([[self::OMARS_REPLY], 0], [$this->replyIdsOf(self::ACME_FOODS), \count($this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE))]);
    }

    #[Test]
    public function aBlankOrTooLongBodyIsRefusedWithItsReasonAndNothingIsWritten(): void
    {
        $refusals = [];
        foreach ([" \t\n", str_repeat('é', 10_001)] as $body) {
            $refusal = $this->refusalOfReply(self::LENAS_COMMENT, self::OMAR, $body);
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame([[MessageBodyRefused::class, 'A reply needs a body.'], [MessageBodyRefused::class, 'A reply can have at most 10000 characters.']], $refusals);
        self::assertSame([[self::OMARS_REPLY], 0], [$this->replyIdsOf(self::ACME_FOODS), \count($this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE))]);
    }

    #[Test]
    public function aMemberOutsideTheTeamGetsTicketNotFoundOnATeamOnlyTicketAndNothingIsWritten(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn (): string => $this->postReply()->post(
            new TicketId(self::TEAM_ONLY_TICKET),
            new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::LENA)),
            self::TEAM_ONLY_COMMENT,
            new UserId(self::MIA),
            'Thanks.',
            new \DateTimeImmutable(self::REPLIED_AT),
        )));

        self::assertSame([TicketNotFound::class, 'That ticket does not exist.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame([], $this->replyRowsOf(self::ACME_FOODS, self::TEAM_ONLY_COMMENT));
        self::assertSame([], $this->outboxBodiesNaming(self::TEAM_ONLY_TICKET));
    }

    #[Test]
    public function anotherOrganizationsMessageOrTicketIsNotFoundEvenByItsOwnAuthorAndNothingIsWrittenInEither(): void
    {
        $betaTicketFacts = new TicketVisibilityFacts(new OrganizationId(self::BETA_BAKERY), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA));
        $refusals = [];
        foreach ([[self::TICKET_FOR_EVERYONE, $this->factsOfTicketForEveryone()], [self::BETA_TICKET, $betaTicketFacts]] as [$ticketId, $ticketFacts]) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn (): string => $this->postReply()->post(
                new TicketId($ticketId),
                $ticketFacts,
                self::BETA_COMMENT,
                new UserId(self::LENA),
                'Thanks.',
                new \DateTimeImmutable(self::REPLIED_AT),
            )));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame([[MessageNotFound::class, 'That message does not exist.'], [TicketNotFound::class, 'That ticket does not exist.']], $refusals);
        self::assertSame([], $this->replyIdsOf(self::BETA_BAKERY));
        self::assertSame([self::OMARS_REPLY], $this->replyIdsOf(self::ACME_FOODS));
        self::assertSame([], $this->outboxBodiesNaming(self::BETA_COMMENT));
    }

    #[Test]
    public function postingOutsideATransactionIsRefusedBeforeTheReplyIsSaved(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $refusal = $this->refusalOf(fn (): string => $this->postReply()->post(
                new TicketId(self::TICKET_FOR_EVERYONE),
                $this->factsOfTicketForEveryone(),
                self::LENAS_COMMENT,
                new UserId(self::OMAR),
                'Thanks.',
                new \DateTimeImmutable(self::REPLIED_AT),
            ));
        } finally {
            $this->entityManager()->clear();
            $this->tenantContext()->clear();
        }

        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertSame([[self::OMARS_REPLY], 0], [$this->replyIdsOf(self::ACME_FOODS), \count($this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE))]);
    }

    #[Test]
    public function aReplyWaitsForARemovalOfItsMessageAndIsThenRefused(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        // The app's event bus writes to the outbox through the first session's connection, so the second publishes here.
        $secondSessionEvents = new RecordingConversationEvents();
        $replyInSecondSession = new PostReply(
            new VisibleTickets(self::visibilitiesForEveryoneOnly(), $this->tenantContext()),
            new ThreadMessages(new DoctrineMessages($secondSession->entityManager)),
            new DoctrineReplies($secondSession->entityManager),
            $secondSessionEvents,
        );
        $postOmarsReply = fn (): string => $replyInSecondSession->post(
            new TicketId(self::TICKET_FOR_EVERYONE),
            $this->factsOfTicketForEveryone(),
            self::LENAS_COMMENT,
            new UserId(self::OMAR),
            'Seen on build 42 too.',
            new \DateTimeImmutable(self::REPLIED_AT),
        );
        $connection = $this->connection();

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $connection->beginTransaction();
            $this->removeMessage()->remove(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET_FOR_EVERYONE), self::LENAS_COMMENT, new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 10:10:00+00:00'));
            // The reply would be saved under the comment being removed unless reading the comment waits for the removal.
            $sqlStateWhileTheRemovalRuns = $secondSession->sqlStateOfFailedAttempt(static function () use ($postOmarsReply): void {
                $postOmarsReply();
            });
            $connection->commit();

            $refusalAfterTheRemoval = $this->refusalOf(static fn (): mixed => $secondSession->entityManager->wrapInTransaction($postOmarsReply));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager()->clear();
            $secondSession->entityManager->clear();
            $this->tenantContext()->clear();
        }

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlStateWhileTheRemovalRuns, 'The reply read the comment while the removal held it.');
        self::assertSame([ReplyRefused::class, 'This message was removed, so you cannot reply to it.'], [$refusalAfterTheRemoval::class, $refusalAfterTheRemoval->getMessage()]);
        self::assertSame([self::OMARS_REPLY], $this->replyIdsOf(self::ACME_FOODS));
        self::assertSame([], $secondSessionEvents->published(), 'A refused reply published.');
    }

    private function refusalOfReply(string $messageId, string $authorId, string $body = 'Thanks.', string $ticketId = self::TICKET_FOR_EVERYONE): \Throwable
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn (): string => $this->postReply()->post(
            new TicketId($ticketId),
            $this->factsOfTicketForEveryone(),
            $messageId,
            new UserId($authorId),
            $body,
            new \DateTimeImmutable(self::REPLIED_AT),
        )));
    }

    /**
     * @param \Closure(): mixed $post
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

    private function factsOfTicketForEveryone(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA));
    }

    /**
     * Sees tickets for everyone and nothing else, without reading the app's session, which holds the removal open.
     */
    private static function visibilitiesForEveryoneOnly(): TicketVisibilities
    {
        return new readonly class implements TicketVisibilities {
            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                return TicketVisibility::for($organizationId, $viewerId, [], false, null);
            }
        };
    }

    /**
     * @return list<list<mixed>> organization, id, author, body, is_answer and created_at of each reply under the
     *                           message, oldest first
     */
    private function replyRowsOf(string $organizationId, string $messageId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT organization_id, id, author_id, body, is_answer, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00')
             FROM cnv_reply WHERE message_id = ? ORDER BY created_at, id",
            [$messageId],
        ));
    }

    /**
     * @return list<mixed> every reply id of the organization, in id order
     */
    private function replyIdsOf(string $organizationId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM cnv_reply ORDER BY id'));
    }

    /**
     * @return list<mixed> resolved_at and resolved_by
     */
    private function resolutionOf(string $organizationId, string $messageId): array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchNumeric(
            'SELECT resolved_at, resolved_by FROM cnv_message WHERE id = ?',
            [$messageId],
        ));
        self::assertIsArray($row, 'The message row is gone.');

        return $row;
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

    private function deleteOutboxRowsOfTheseTickets(): void
    {
        foreach ([self::TICKET_FOR_EVERYONE, self::TEAM_ONLY_TICKET, self::BETA_TICKET] as $ticketId) {
            $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$ticketId.'%']);
        }
    }

    private function postReply(): PostReply
    {
        $postReply = self::getContainer()->get('test.conversation_post_reply');
        self::assertInstanceOf(PostReply::class, $postReply);

        return $postReply;
    }

    private function removeMessage(): RemoveMessage
    {
        $removeMessage = self::getContainer()->get('test.conversation_remove_message');
        self::assertInstanceOf(RemoveMessage::class, $removeMessage);

        return $removeMessage;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), $this->tenantContext());
    }

    private function threadRows(): ThreadRows
    {
        return new ThreadRows($this->migrator(), $this->tenantContext());
    }
}
