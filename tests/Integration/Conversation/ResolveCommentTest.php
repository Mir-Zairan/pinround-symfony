<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\Event\CommentResolved;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\ResolveRefused;
use App\Conversation\Application\ResolveComment\ResolveComment;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Infrastructure\Doctrine\DoctrineMessages;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ResolveComment on the real tables, on Omar's comments posted at 10:00 on a fixed day on a ticket whose team Lena
 * leads. A refusal is caught inside a transaction that then commits, so "unchanged" proves the check comes before the
 * write, not that a rollback undid it.
 */
final class ResolveCommentTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c502';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c503';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c504';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000c505';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000c506';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-00000000c507';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c511';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c512';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c513';
    private const string OMARS_COMMENT = '0197a3c0-0000-7000-8000-00000000c521';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-00000000c522';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-00000000c523';
    private const string BETA_COMMENT = '0197a3c0-0000-7000-8000-00000000c524';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000c5ff';
    private const string BODY = 'The button moves on small screens.';
    private const array UNRESOLVED = [null, null];

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::OMARS_COMMENT, self::OMAR, self::BODY, '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::REMOVED_COMMENT, self::OMAR, self::BODY, '2027-03-15 10:00:00+00', '2027-03-15 10:02:00+00');
        $this->threadRows()->addOpenQuestionOf(self::ACME_FOODS, self::TICKET, self::OPEN_QUESTION, self::OMAR, self::MIA, self::BODY, '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_COMMENT, self::OMAR, self::BODY, '2027-03-15 10:00:00+00');
        $this->deleteOutboxRowsNaming(self::TICKET);
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->deleteOutboxRowsNaming(self::TICKET);
        parent::tearDown();
    }

    #[Test]
    public function theLeadsResolveOfSomeoneElsesCommentIsSavedWithOneCommentResolvedInTheOutbox(): void
    {
        $this->resolveAsLena(self::OMARS_COMMENT, '2027-03-15 11:30:00+00:00');

        self::assertSame(['2027-03-15 11:30:00+00', self::LENA], $this->resolvedStateOf(self::ACME_FOODS, self::OMARS_COMMENT));
        $outboxBodies = $this->outboxBodiesNaming(self::OMARS_COMMENT);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', CommentResolved::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringContainsString(self::TICKET, $outboxBodies[0]);
    }

    #[Test]
    public function theAuthorsResolveOnATicketWithNoTeamLeadIsSaved(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->resolveComment()->resolve(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::OMARS_COMMENT,
            new UserId(self::OMAR),
            null,
            new \DateTimeImmutable('2027-03-15 11:30:00+00:00'),
            self::allowEveryone(...),
        ));

        self::assertSame(['2027-03-15 11:30:00+00', self::OMAR], $this->resolvedStateOf(self::ACME_FOODS, self::OMARS_COMMENT));
    }

    #[Test]
    public function aMembersResolveOfSomeoneElsesCommentIsRefusedWithItsReasonAndWritesNothing(): void
    {
        $refusal = $this->refusalOfResolve(self::OMARS_COMMENT, self::MIA);

        self::assertSame([ResolveRefused::class, 'Only the person who wrote a comment or the lead of the ticket\'s team can resolve it.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNRESOLVED, $this->resolvedStateOf(self::ACME_FOODS, self::OMARS_COMMENT));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
    }

    #[Test]
    public function aQuestionIsNotResolvableByResolveAndStaysOpen(): void
    {
        $refusal = $this->refusalOfResolve(self::OPEN_QUESTION, self::OMAR);

        self::assertSame([ResolveRefused::class, 'A question is closed by its answer, not by Resolve.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNRESOLVED, $this->resolvedStateOf(self::ACME_FOODS, self::OPEN_QUESTION));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
    }

    #[Test]
    public function aRemovedCommentIsNotResolved(): void
    {
        $refusal = $this->refusalOfResolve(self::REMOVED_COMMENT, self::OMAR);

        self::assertSame([ResolveRefused::class, 'This comment was removed, so it cannot be resolved.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNRESOLVED, $this->resolvedStateOf(self::ACME_FOODS, self::REMOVED_COMMENT));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
    }

    #[Test]
    public function aSecondResolveIsRefusedAndKeepsTheFirstResolverAndTime(): void
    {
        $this->resolveAsLena(self::OMARS_COMMENT, '2027-03-15 11:30:00+00:00');

        $refusal = $this->refusalOfResolve(self::OMARS_COMMENT, self::OMAR);

        self::assertSame([ResolveRefused::class, 'This comment is already resolved.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(['2027-03-15 11:30:00+00', self::LENA], $this->resolvedStateOf(self::ACME_FOODS, self::OMARS_COMMENT));
        self::assertCount(1, $this->outboxBodiesNaming(self::OMARS_COMMENT));
    }

    #[Test]
    public function anUnknownMalformedOrOtherTicketsMessageIdIsNotFound(): void
    {
        $refusals = [];
        foreach ([[self::TICKET, self::UNKNOWN_MESSAGE], [self::TICKET, 'not-a-message-id'], [self::OTHER_TICKET, self::OMARS_COMMENT]] as [$ticketId, $messageId]) {
            $refusal = $this->refusalOfResolve($messageId, self::OMAR, $ticketId);
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 3, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame(self::UNRESOLVED, $this->resolvedStateOf(self::ACME_FOODS, self::OMARS_COMMENT));
        self::assertSame([], $this->outboxBodiesNaming(self::OMARS_COMMENT));
    }

    #[Test]
    public function anotherOrganizationsCommentIsNotFoundEvenByItsOwnAuthorAndStaysUnresolved(): void
    {
        $refusals = [];
        foreach ([[self::ACME_FOODS, self::TICKET], [self::BETA_BAKERY, self::BETA_TICKET]] as [$organizationId, $ticketId]) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->resolveComment()->resolve(
                new OrganizationId($organizationId),
                new TicketId($ticketId),
                self::BETA_COMMENT,
                new UserId(self::OMAR),
                new UserId(self::LENA),
                new \DateTimeImmutable('2027-03-15 11:30:00+00:00'),
                self::allowEveryone(...),
            )));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 2, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame(self::UNRESOLVED, $this->resolvedStateOf(self::BETA_BAKERY, self::BETA_COMMENT));
        self::assertSame([], $this->outboxBodiesNaming(self::BETA_COMMENT));
    }

    #[Test]
    public function aSecondResolveWaitsUntilTheFirstEndsAndThenIsRefusedSoOnlyOneCommentResolvedIsPublished(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        $secondSessionMessages = new DoctrineMessages($secondSession->entityManager);
        // The app's event bus writes to the outbox through the first session's connection, so the second publishes here.
        $secondSessionEvents = new RecordingConversationEvents();
        $resolveInSecondSession = new ResolveComment(new ThreadMessages($secondSessionMessages), $secondSessionMessages, $secondSessionEvents);
        $resolveAsTheAuthor = static fn () => $resolveInSecondSession->resolve(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::OMARS_COMMENT,
            new UserId(self::OMAR),
            new UserId(self::LENA),
            new \DateTimeImmutable('2027-03-15 11:31:00+00:00'),
            self::allowEveryone(...),
        );
        $connection = $this->connection();
        $refusalAfterTheFirst = null;

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $connection->beginTransaction();
            $this->resolveComment()->resolve(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::OMARS_COMMENT, new UserId(self::LENA), new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 11:30:00+00:00'), self::allowEveryone(...));
            // Omar's resolve would pass the "already resolved" check unless reading the comment waits for the first.
            $sqlStateWhileTheFirstRuns = $secondSession->sqlStateOfFailedAttempt($resolveAsTheAuthor);
            $connection->commit();

            try {
                $secondSession->entityManager->wrapInTransaction($resolveAsTheAuthor);
            } catch (ResolveRefused $refusal) {
                $refusalAfterTheFirst = $refusal->getMessage();
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager()->clear();
            $secondSession->entityManager->clear();
            $this->tenantContext()->clear();
        }

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlStateWhileTheFirstRuns, 'The second resolve read the comment while the first held it.');
        self::assertSame('This comment is already resolved.', $refusalAfterTheFirst);
        self::assertSame(['2027-03-15 11:30:00+00', self::LENA], $this->resolvedStateOf(self::ACME_FOODS, self::OMARS_COMMENT));
        self::assertCount(1, $this->outboxBodiesNaming(self::OMARS_COMMENT));
        self::assertSame([], $secondSessionEvents->published(), 'The refused resolve published.');
    }

    private function resolveAsLena(string $messageId, string $resolvedAt): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->resolveComment()->resolve(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            $messageId,
            new UserId(self::LENA),
            new UserId(self::LENA),
            new \DateTimeImmutable($resolvedAt),
            self::allowEveryone(...),
        ));
    }

    /**
     * The controller's MessageVoter check, which ResolveCommentTest (unit) and ReplyAndResolveTest cover.
     */
    private static function allowEveryone(UserId $authorId): void
    {
    }

    private function refusalOfResolve(string $messageId, string $resolverId, string $ticketId = self::TICKET): \Throwable
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->resolveComment()->resolve(
            new OrganizationId(self::ACME_FOODS),
            new TicketId($ticketId),
            $messageId,
            new UserId($resolverId),
            new UserId(self::LENA),
            new \DateTimeImmutable('2027-03-15 11:45:00+00:00'),
            self::allowEveryone(...),
        )));
    }

    /**
     * @param \Closure(): mixed $resolve
     */
    private function refusalOf(\Closure $resolve): \Throwable
    {
        try {
            $resolve();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('The comment was resolved.');
    }

    /**
     * @return list<mixed> resolved_at and resolved_by
     */
    private function resolvedStateOf(string $organizationId, string $messageId): array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchNumeric(
            "SELECT to_char(resolved_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00'), resolved_by FROM cnv_message WHERE id = ?",
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

    private function deleteOutboxRowsNaming(string $id): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$id.'%']);
    }

    private function resolveComment(): ResolveComment
    {
        $resolveComment = self::getContainer()->get('test.conversation_resolve_comment');
        self::assertInstanceOf(ResolveComment::class, $resolveComment);

        return $resolveComment;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
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
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function threadRows(): ThreadRows
    {
        return new ThreadRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
