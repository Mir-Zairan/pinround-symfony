<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The checks and the open-question unique index that cnv_message and cnv_reply keep on their own
 * (Version20261114120000), written as the app: one row each refuses, and the rows next to them it must still accept.
 */
final class ConversationTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004211';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000004212';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004213';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004214';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004215';
    private const string MIA = '0197a3c0-0000-7000-8000-000000004216';
    private const string MESSAGE = '0197a3c0-0000-7000-8000-000000004217';
    private const string SECOND_MESSAGE = '0197a3c0-0000-7000-8000-000000004218';
    private const string REPLY = '0197a3c0-0000-7000-8000-000000004219';
    private const string CHECK_VIOLATION = '23514';
    private const string UNIQUE_VIOLATION = '23505';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function theCasesCoverEveryCheckOfBothTables(): void
    {
        self::assertEqualsCanonicalizing(
            ['cnv_message_kind_check', 'cnv_message_to_user_id_check', 'cnv_message_resolved_check', 'cnv_message_body_check', 'cnv_reply_body_check'],
            $this->connection()->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid IN ('public.cnv_message'::regclass, 'public.cnv_reply'::regclass) AND contype = 'c'"),
        );
    }

    /**
     * @return iterable<string, array{non-empty-list<array{string, array<string, mixed>}>, string, string}>
     */
    public static function rowsTheDatabaseRefuses(): iterable
    {
        yield 'an unknown kind' => [[self::message(['kind' => 'note'])], 'cnv_message_kind_check', self::CHECK_VIOLATION];
        yield 'a question to nobody' => [[self::message(['kind' => 'question'])], 'cnv_message_to_user_id_check', self::CHECK_VIOLATION];
        yield 'a comment to someone' => [[self::message(['to_user_id' => self::OMAR])], 'cnv_message_to_user_id_check', self::CHECK_VIOLATION];
        yield 'resolved by nobody' => [[self::message(['resolved_at' => '2027-03-16 09:00:00+00'])], 'cnv_message_resolved_check', self::CHECK_VIOLATION];
        yield 'resolved by someone at no time' => [[self::message(['resolved_by' => self::MIA])], 'cnv_message_resolved_check', self::CHECK_VIOLATION];
        yield 'an empty message' => [[self::message(['body' => ''])], 'cnv_message_body_check', self::CHECK_VIOLATION];
        yield 'a message one character over the cap' => [[self::message(['body' => str_repeat('é', 10001)])], 'cnv_message_body_check', self::CHECK_VIOLATION];
        yield 'a second open question on the ticket' => [
            [self::question(self::MESSAGE, self::OMAR, []), self::question(self::SECOND_MESSAGE, self::MIA, [])],
            'cnv_message_ticket_id_open_question_key',
            self::UNIQUE_VIOLATION,
        ];
        yield 'an empty reply' => [[self::message([]), self::reply(['body' => ''])], 'cnv_reply_body_check', self::CHECK_VIOLATION];
        yield 'a reply one character over the cap' => [[self::message([]), self::reply(['body' => str_repeat('é', 10001)])], 'cnv_reply_body_check', self::CHECK_VIOLATION];
    }

    /**
     * @param non-empty-list<array{string, array<string, mixed>}> $rows the last one is refused
     */
    #[Test]
    #[DataProvider('rowsTheDatabaseRefuses')]
    public function theDatabaseRefusesTheRow(array $rows, string $violatedConstraint, string $sqlState): void
    {
        $refusedTable = $rows[array_key_last($rows)][0];
        $rowsBefore = \count(array_filter(\array_slice($rows, 0, -1), static fn (array $row): bool => $row[0] === $refusedTable));

        self::assertSame([$sqlState, true, $rowsBefore], $this->inserts()->refusalOfLast($rows, $violatedConstraint));
    }

    /**
     * @return iterable<string, array{non-empty-list<array{string, array<string, mixed>}>}>
     */
    public static function rowsTheDatabaseAccepts(): iterable
    {
        $resolved = ['resolved_at' => '2027-03-16 09:00:00+00', 'resolved_by' => self::MIA];
        yield 'a resolved comment' => [[self::message($resolved)]];
        yield 'an answered question' => [[self::question(self::MESSAGE, self::OMAR, $resolved)]];
        yield 'a new open question after the open one was removed' => [
            [self::question(self::MESSAGE, self::OMAR, ['removed_at' => '2027-03-15 12:00:00+00']), self::question(self::SECOND_MESSAGE, self::MIA, [])],
        ];
        yield 'a new open question after the last one was answered' => [
            [self::question(self::MESSAGE, self::OMAR, $resolved), self::question(self::SECOND_MESSAGE, self::MIA, [])],
        ];
        yield 'a message and a reply of exactly the cap' => [
            [self::message(['body' => str_repeat('é', 10000)]), self::reply(['body' => str_repeat('é', 10000)])],
        ];
    }

    /**
     * @param non-empty-list<array{string, array<string, mixed>}> $rows
     */
    #[Test]
    #[DataProvider('rowsTheDatabaseAccepts')]
    public function theDatabaseAcceptsTheRows(array $rows): void
    {
        self::assertSame(array_fill(0, \count($rows), 1), $this->inserts()->insertAll($rows));
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid comment
     *
     * @return array{string, array<string, mixed>}
     */
    private static function message(array $change): array
    {
        return ['cnv_message', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::MESSAGE,
            'ticket_id' => self::TICKET,
            'author_id' => self::LENA,
            'kind' => 'comment',
            'body' => 'The button moves on small screens.',
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array{string, array<string, mixed>}
     */
    private static function question(string $id, string $toUserId, array $change): array
    {
        return self::message(['id' => $id, 'kind' => 'question', 'to_user_id' => $toUserId, 'body' => 'Which build shows it?', ...$change]);
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid reply to MESSAGE
     *
     * @return array{string, array<string, mixed>}
     */
    private static function reply(array $change): array
    {
        return ['cnv_reply', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::REPLY,
            'message_id' => self::MESSAGE,
            'author_id' => self::OMAR,
            'body' => 'Seen it on a phone too.',
            'is_answer' => 'false',
            'created_at' => '2027-03-15 11:00:00+00',
            ...$change,
        ]];
    }

    private function inserts(): RolledBackInserts
    {
        return new RolledBackInserts($this->connection(), self::getContainer()->get(TenantContext::class), self::ACME_FOODS);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
