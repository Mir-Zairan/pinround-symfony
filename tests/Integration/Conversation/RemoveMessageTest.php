<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\MessageRemovalRefused;
use App\Conversation\Application\RemoveMessage\RemoveMessage;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RemoveMessage on the real tables, on comments posted at 10:00 on a fixed day. A refusal is caught inside a transaction
 * that then commits, so "unchanged" proves the check comes before the write, not that a rollback undid it.
 */
final class RemoveMessageTest extends KernelTestCase
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
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000c521';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-00000000c522';
    private const string BETA_COMMENT = '0197a3c0-0000-7000-8000-00000000c523';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000c5ff';
    private const string ORIGINAL_BODY = 'The button moves on small screens.';
    private const array UNCHANGED = [self::ORIGINAL_BODY, null, null];

    protected function setUp(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
        $this->threadRows()->addOpenQuestionOf(self::ACME_FOODS, self::TICKET, self::OPEN_QUESTION, self::LENA, self::OMAR, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_COMMENT, self::LENA, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
        $this->deleteOutboxRowsNaming(self::TICKET);
    }

    protected function tearDown(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->deleteOutboxRowsNaming(self::TICKET);
        parent::tearDown();
    }

    #[Test]
    public function theAuthorRemovesTheirCommentADayLaterAndItKeepsItsBodyWithOneMessageRemovedInTheOutbox(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->removeMessage()->remove(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::LENAS_COMMENT,
            new UserId(self::LENA),
            new \DateTimeImmutable('2027-03-16 09:00:00+00:00'),
        ));

        self::assertSame([self::ORIGINAL_BODY, null, '2027-03-16 09:00:00+00'], $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
        $outboxBodies = $this->outboxBodiesNaming(self::LENAS_COMMENT);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', MessageRemoved::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringContainsString(self::TICKET, $outboxBodies[0]);
    }

    #[Test]
    public function someoneElsesRemovalIsRefusedWithItsReasonAndTheCommentStaysWithNoOutboxRow(): void
    {
        $refusal = $this->refusalOfRemoval(self::LENAS_COMMENT, self::OMAR, '2027-03-15 10:05:00+00:00');

        self::assertSame([MessageRemovalRefused::class, 'Only the person who wrote a message can remove it.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
    }

    #[Test]
    public function aSecondRemovalIsRefusedAndKeepsTheFirstRemovalTime(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->removeMessage()->remove(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::LENAS_COMMENT,
            new UserId(self::LENA),
            new \DateTimeImmutable('2027-03-15 10:05:00+00:00'),
        ));

        $refusal = $this->refusalOfRemoval(self::LENAS_COMMENT, self::LENA, '2027-03-15 10:30:00+00:00');

        self::assertSame([MessageRemovalRefused::class, 'This message is already removed.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame([self::ORIGINAL_BODY, null, '2027-03-15 10:05:00+00'], $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    #[Test]
    public function aQuestionIsNotRemoved(): void
    {
        $refusal = $this->refusalOfRemoval(self::OPEN_QUESTION, self::LENA, '2027-03-15 10:05:00+00:00');

        self::assertSame([MessageRemovalRefused::class, 'A question cannot be removed.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::OPEN_QUESTION));
    }

    #[Test]
    public function anUnknownMalformedOrOtherTicketsMessageIdIsNotFound(): void
    {
        $refusals = [];
        foreach ([[self::TICKET, self::UNKNOWN_MESSAGE], [self::TICKET, 'not-a-message-id'], [self::OTHER_TICKET, self::LENAS_COMMENT]] as [$ticketId, $messageId]) {
            $refusal = $this->refusalOfRemoval($messageId, self::LENA, '2027-03-15 10:05:00+00:00', $ticketId);
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 3, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    #[Test]
    public function anotherOrganizationsMessageIsNotFoundEvenByItsOwnAuthorAndStaysUnchanged(): void
    {
        $refusals = [];
        foreach ([[self::ACME_FOODS, self::TICKET], [self::BETA_BAKERY, self::BETA_TICKET]] as [$organizationId, $ticketId]) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->removeMessage()->remove(
                new OrganizationId($organizationId),
                new TicketId($ticketId),
                self::BETA_COMMENT,
                new UserId(self::LENA),
                new \DateTimeImmutable('2027-03-15 10:05:00+00:00'),
            )));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 2, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::BETA_BAKERY, self::BETA_COMMENT));
    }

    private function refusalOfRemoval(string $messageId, string $removerId, string $removedAt, string $ticketId = self::TICKET): \Throwable
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->removeMessage()->remove(
            new OrganizationId(self::ACME_FOODS),
            new TicketId($ticketId),
            $messageId,
            new UserId($removerId),
            new \DateTimeImmutable($removedAt),
        )));
    }

    /**
     * @param \Closure(): mixed $removal
     */
    private function refusalOf(\Closure $removal): \Throwable
    {
        try {
            $removal();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('The message was removed.');
    }

    /**
     * @return list<mixed> body, edited_at and removed_at
     */
    private function messageRowOf(string $organizationId, string $messageId): array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchNumeric(
            "SELECT body, to_char(edited_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00'), to_char(removed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00')
             FROM cnv_message WHERE id = ?",
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
