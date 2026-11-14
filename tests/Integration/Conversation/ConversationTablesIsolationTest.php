<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Row-level security on every cnv_ table (Version20261114120000, ADR-0005) as pinround_app: each organization sees only
 * its own messages and replies, none without a tenant, and cannot add one in the name of another organization.
 * Removing a ticket removes its thread by the ON DELETE CASCADE keys.
 */
final class ConversationTablesIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004121';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-000000004122';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000004123';
    private const string ACME_TICKET = '0197a3c0-0000-7000-8000-000000004124';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000004125';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004126';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004127';
    private const string ACME_MESSAGE = '0197a3c0-0000-7000-8000-000000004128';
    private const string OTHER_MESSAGE = '0197a3c0-0000-7000-8000-000000004129';
    private const string ACME_REPLY = '0197a3c0-0000-7000-8000-00000000412a';
    private const string OTHER_REPLY = '0197a3c0-0000-7000-8000-00000000412b';
    private const string SMUGGLED_MESSAGE = '0197a3c0-0000-7000-8000-00000000412c';
    private const string SMUGGLED_REPLY = '0197a3c0-0000-7000-8000-00000000412d';
    private const array TABLES = ['cnv_message', 'cnv_reply'];
    private const string MESSAGE_INSERT = "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at) VALUES (?, ?, ?, ?, 'comment', 'The button hides on small screens.', '2027-03-15 10:00:00+00')";
    private const string REPLY_INSERT = "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at) VALUES (?, ?, ?, ?, 'Seen it on a phone too.', false, '2027-03-15 11:00:00+00')";

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::ACME_TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::OTHER_ORGANIZATION, self::OTHER_TICKET, self::SHOP_PROJECT, self::OMAR);
        $this->addThreadOf(self::ACME_FOODS, self::ACME_TICKET, self::ACME_MESSAGE, self::ACME_REPLY, self::LENA);
        $this->addThreadOf(self::OTHER_ORGANIZATION, self::OTHER_TICKET, self::OTHER_MESSAGE, self::OTHER_REPLY, self::OMAR);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function theCasesCoverEveryConversationTableInTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            self::TABLES,
            $this->connection()->fetchFirstColumn("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND NOT relispartition AND relname LIKE 'cnv\\_%'"),
        );
    }

    #[Test]
    public function eachOrganizationSeesOnlyItsOwnMessagesAndRepliesAndNoneWithoutATenant(): void
    {
        self::assertSame([self::ACME_MESSAGE], $this->idsAs(self::ACME_FOODS, 'cnv_message'));
        self::assertSame([self::OTHER_MESSAGE], $this->idsAs(self::OTHER_ORGANIZATION, 'cnv_message'));
        self::assertSame([self::ACME_REPLY], $this->idsAs(self::ACME_FOODS, 'cnv_reply'));
        self::assertSame([self::OTHER_REPLY], $this->idsAs(self::OTHER_ORGANIZATION, 'cnv_reply'));
        foreach (self::TABLES as $table) {
            self::assertSame(0, $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)), $table);
        }
    }

    #[Test]
    public function anotherOrganizationCannotAddAMessageInTheNameOfTheOrganization(): void
    {
        $this->assertRefusedByRowLevelSecurity(
            'cnv_message',
            fn (): int|string => $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement(
                self::MESSAGE_INSERT,
                [self::ACME_FOODS, self::SMUGGLED_MESSAGE, self::ACME_TICKET, self::OMAR],
            )),
        );

        self::assertSame([self::ACME_MESSAGE], $this->idsAs(self::ACME_FOODS, 'cnv_message'));
    }

    #[Test]
    public function anotherOrganizationCannotAddAReplyInTheNameOfTheOrganization(): void
    {
        $this->assertRefusedByRowLevelSecurity(
            'cnv_reply',
            fn (): int|string => $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement(
                self::REPLY_INSERT,
                [self::ACME_FOODS, self::SMUGGLED_REPLY, self::ACME_MESSAGE, self::OMAR],
            )),
        );

        self::assertSame([self::ACME_REPLY], $this->idsAs(self::ACME_FOODS, 'cnv_reply'));
    }

    #[Test]
    public function removingATicketRemovesItsMessagesAndTheirReplies(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);

        self::assertSame([], $this->idsAs(self::ACME_FOODS, 'cnv_message'));
        self::assertSame([], $this->idsAs(self::ACME_FOODS, 'cnv_reply'));
        self::assertSame([self::OTHER_MESSAGE], $this->idsAs(self::OTHER_ORGANIZATION, 'cnv_message'), 'Another organization\'s thread went with the ticket too.');
        self::assertSame([self::OTHER_REPLY], $this->idsAs(self::OTHER_ORGANIZATION, 'cnv_reply'), 'Another organization\'s thread went with the ticket too.');
    }

    private function addThreadOf(string $organizationId, string $ticketId, string $messageId, string $replyId, string $authorId): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $ticketId, $messageId, $replyId, $authorId): void {
            $this->connection()->executeStatement(self::MESSAGE_INSERT, [$organizationId, $messageId, $ticketId, $authorId]);
            $this->connection()->executeStatement(self::REPLY_INSERT, [$organizationId, $replyId, $messageId, $authorId]);
        });
    }

    /**
     * @return list<mixed>
     */
    private function idsAs(string $organizationId, string $table): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', $table)));
    }

    private function assertRefusedByRowLevelSecurity(string $table, \Closure $work): void
    {
        try {
            $work();
            self::fail(\sprintf('Row-level security did not refuse an insert into %s.', $table));
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $refusal->getMessage());
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
