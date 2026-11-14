<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\MessageView;
use App\Conversation\Api\ThreadView;
use App\Conversation\Infrastructure\Doctrine\DbalThreadQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalThreadQueries on the real cnv_message: order, the 200-message cap, removed text, and what it leaves out.
 */
final class DbalThreadQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d401';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000d402';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000d403';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000d404';
    private const string THREAD_TICKET = '0197a3c0-0000-7000-8000-00000000d405';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000d406';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-00000000d407';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000d411';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000d412';
    private const string FIRST_MESSAGE = '0197a3c0-0000-7000-8000-00000000d421';
    private const string SECOND_MESSAGE = '0197a3c0-0000-7000-8000-00000000d422';
    private const string TIED_LOWER_ID = '0197a3c0-0000-7000-8000-00000000d423';
    private const string TIED_HIGHER_ID = '0197a3c0-0000-7000-8000-00000000d424';
    private const string OTHER_TICKET_MESSAGE = '0197a3c0-0000-7000-8000-00000000d425';
    private const string BETA_MESSAGE = '0197a3c0-0000-7000-8000-00000000d426';
    private const string REMOVED_MESSAGE = '0197a3c0-0000-7000-8000-00000000d427';
    private const string REMOVED_TEXT = 'The staging password is in the wiki.';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::THREAD_TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::THREAD_TICKET, self::BETA_PROJECT, self::LENA, 2);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theThreadShowsTheNewestLastWithTiesByIdAndLeavesOutOtherTicketsAndOrganizations(): void
    {
        // Inserted out of order; the tied pair goes in higher id first, so insertion order cannot pass for id order.
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::SECOND_MESSAGE, 'comment', 'Which build shows it?', '2027-03-15 10:05:00+00', editedAt: '2027-03-15 10:07:00+00');
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::TIED_HIGHER_ID, 'comment', 'Build 42.', '2027-03-15 10:10:00+00');
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::FIRST_MESSAGE, 'question', 'Can you check the small screen?', '2027-03-15 10:00:00+00');
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::TIED_LOWER_ID, 'comment', 'Also on tablets.', '2027-03-15 10:10:00+00');
        $this->addMessage(self::ACME_FOODS, self::OTHER_TICKET, self::OTHER_TICKET_MESSAGE, 'comment', 'Another ticket.', '2027-03-15 10:03:00+00');
        $this->addMessage(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_MESSAGE, 'comment', 'Another organization.', '2027-03-15 10:04:00+00');
        // The same ticket id under another organization: only row-level security and the organization filter keep it out.
        $this->addMessage(self::BETA_BAKERY, self::THREAD_TICKET, '0197a3c0-0000-7000-8000-00000000d428', 'comment', 'Beta again.', '2027-03-15 10:06:00+00');

        $thread = $this->threadOf(self::THREAD_TICKET);

        self::assertNotSame([], $thread->messages);
        self::assertSame(
            [self::FIRST_MESSAGE, self::SECOND_MESSAGE, self::TIED_LOWER_ID, self::TIED_HIGHER_ID],
            array_map(static fn (MessageView $message): string => $message->id, $thread->messages),
        );
        self::assertFalse($thread->hasEarlierMessages);

        $first = $thread->messages[0];
        self::assertSame(MessageView::KIND_QUESTION, $first->kind);
        self::assertSame(self::OMAR, $first->authorId->toString());
        self::assertSame('Can you check the small screen?', $first->body);
        self::assertFalse($first->isRemoved);
        self::assertNull($first->editedAt);
        self::assertSame('2027-03-15T10:00:00+00:00', $first->createdAt->format(\DATE_ATOM));
        self::assertSame('2027-03-15T10:15:00+00:00', $first->editableUntil->format(\DATE_ATOM));

        $second = $thread->messages[1];
        self::assertSame(MessageView::KIND_COMMENT, $second->kind);
        self::assertSame('2027-03-15T10:07:00+00:00', $second->editedAt?->format(\DATE_ATOM));
        self::assertSame('2027-03-15T10:20:00+00:00', $second->editableUntil->format(\DATE_ATOM));
    }

    #[Test]
    public function aRemovedMessageHasNoBodyAndTheQueryNeverReturnsItsText(): void
    {
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::FIRST_MESSAGE, 'comment', 'Still here.', '2027-03-15 10:00:00+00');
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::REMOVED_MESSAGE, 'comment', self::REMOVED_TEXT, '2027-03-15 10:01:00+00', removedAt: '2027-03-15 10:02:00+00');
        $recording = $this->recordingConnection();

        $thread = $this->threadOf(self::THREAD_TICKET, $recording);

        self::assertSame([self::FIRST_MESSAGE, self::REMOVED_MESSAGE], array_map(static fn (MessageView $message): string => $message->id, $thread->messages));
        self::assertSame('Still here.', $thread->messages[0]->body);
        self::assertFalse($thread->messages[0]->isRemoved);
        self::assertNull($thread->messages[1]->body);
        self::assertTrue($thread->messages[1]->isRemoved);
        self::assertNotSame([], $recording->returnedRows);
        self::assertStringNotContainsString(self::REMOVED_TEXT, serialize($recording->returnedRows));
        self::assertStringContainsString('Still here.', serialize($recording->returnedRows));
    }

    #[Test]
    public function aThreadOfMoreThanTwoHundredMessagesShowsTheNewestTwoHundredAndSaysThereAreEarlierOnes(): void
    {
        $this->addNumberedMessages(ThreadView::MESSAGE_LIMIT + 1);

        $thread = $this->threadOf(self::THREAD_TICKET);

        self::assertCount(ThreadView::MESSAGE_LIMIT, $thread->messages);
        self::assertTrue($thread->hasEarlierMessages);
        self::assertSame('Message 2', $thread->messages[0]->body);
        self::assertSame('Message 201', $thread->messages[ThreadView::MESSAGE_LIMIT - 1]->body);
    }

    #[Test]
    public function aThreadOfExactlyTwoHundredMessagesShowsThemAllWithNoEarlierOnes(): void
    {
        $this->addNumberedMessages(ThreadView::MESSAGE_LIMIT);

        $thread = $this->threadOf(self::THREAD_TICKET);

        self::assertCount(ThreadView::MESSAGE_LIMIT, $thread->messages);
        self::assertFalse($thread->hasEarlierMessages);
        self::assertSame('Message 1', $thread->messages[0]->body);
        self::assertSame('Message 200', $thread->messages[ThreadView::MESSAGE_LIMIT - 1]->body);
    }

    #[Test]
    public function theQueryReadsTheTicketCreatedAtIdIndexWithNoSort(): void
    {
        $this->addMessage(self::ACME_FOODS, self::THREAD_TICKET, self::FIRST_MESSAGE, 'comment', 'Still here.', '2027-03-15 10:00:00+00');
        $recording = $this->recordingConnection();
        $this->threadOf(self::THREAD_TICKET, $recording);
        self::assertNotNull($recording->lastSql);

        $plan = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($recording): string {
            // A handful of rows makes a sequential scan cheapest; this asks whether the index can serve the query at all.
            $this->connection()->executeStatement('SET LOCAL enable_seqscan = off');

            return implode("\n", array_filter($this->connection()->fetchFirstColumn('EXPLAIN '.$recording->lastSql, $recording->lastParams), \is_string(...)));
        });

        self::assertStringContainsString('cnv_message_ticket_id_created_at_id_idx', $plan);
        self::assertStringNotContainsString('Sort', $plan);
    }

    #[Test]
    public function theThreadRefusesToReadWithNoOpenTransaction(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->expectExceptionObject(new \LogicException('A thread is read in a transaction under the organization as tenant; none is open.'));
            new DbalThreadQueries($this->connection(), $this->tenantContext())->threadOf(new TicketId(self::THREAD_TICKET));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function threadOf(string $ticketId, ?Connection $connection = null): ThreadView
    {
        $queries = new DbalThreadQueries($connection ?? $this->connection(), $this->tenantContext());
        if (null === $connection) {
            return $this->transactions()->committedAs(self::ACME_FOODS, static fn (): ThreadView => $queries->threadOf(new TicketId($ticketId)));
        }

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $connection->beginTransaction();
        try {
            return $queries->threadOf(new TicketId($ticketId));
        } finally {
            $connection->rollBack();
            $this->tenantContext()->clear();
            $connection->close();
        }
    }

    /**
     * Messages "Message 1" to "Message <count>", a minute apart, on the thread ticket.
     */
    private function addNumberedMessages(int $count): void
    {
        $this->asTenant(self::ACME_FOODS, fn (): int|string => $this->migrator()->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at)
             SELECT ?, CAST('0197a3c0-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), ?, ?, 'comment', 'Message ' || n,
                 TIMESTAMPTZ '2027-03-15 10:00:00+00' + n * INTERVAL '1 minute'
             FROM generate_series(1, ?) AS n",
            [self::ACME_FOODS, self::THREAD_TICKET, self::OMAR, $count],
        ));
    }

    private function addMessage(string $organizationId, string $ticketId, string $messageId, string $kind, string $body, string $createdAt, ?string $editedAt = null, ?string $removedAt = null): void
    {
        $this->asTenant($organizationId, fn (): int|string => $this->migrator()->executeStatement(
            'INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, edited_at, removed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$organizationId, $messageId, $ticketId, self::OMAR, $kind, 'question' === $kind ? self::LENA : null, $body, $editedAt, $removedAt, $createdAt],
        ));
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function asTenant(string $organizationId, \Closure $work): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * A second app connection, through the same driver and its tenant middleware, that keeps what the database sent.
     */
    private function recordingConnection(): RecordingConnection
    {
        $app = $this->connection();

        return new RecordingConnection($app->getParams(), $app->getDriver(), $app->getConfiguration());
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
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), $this->tenantContext());
    }
}
