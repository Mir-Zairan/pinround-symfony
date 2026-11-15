<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Application\Bell\BellKind;
use App\Notification\Application\Notify\NotificationKind;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Conversation\RolledBackInserts;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The checks and keys that ntf_ping, ntf_notification and ntf_delivery keep on their own (Version20261114130000; the
 * kind check, Version20261115163000), written as the app: one row each refuses, and the rows next to them it must still
 * accept. Notifications sit in March 2027, a month whose partition the migration always creates.
 */
final class NotificationTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004231';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000004232';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004233';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004234';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004235';
    private const string PING = '0197a3c0-0000-7000-8000-000000004236';
    private const string NOTIFICATION = '0197a3c0-0000-7000-8000-000000004237';
    private const string CHECK_VIOLATION = '23514';
    private const string UNIQUE_VIOLATION = '23505';

    /** realtime-and-notifications.md › Notification kinds. */
    private const array DOCUMENTED_KINDS = ['ping', 'question', 'answer', 'reply', 'assigned', 'your_turn', 'check', 'round_shared', 'closed_duplicate'];

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
    public function theCasesCoverEveryCheckOfTheNotificationTables(): void
    {
        self::assertEqualsCanonicalizing(
            ['ntf_ping_body_check', 'ntf_ping_cleared_reason_check', 'ntf_ping_cleared_check', 'ntf_notification_data_check', 'ntf_notification_kind_check', 'ntf_delivery_window_key_check'],
            $this->connection()->fetchFirstColumn(
                "SELECT conname FROM pg_constraint WHERE contype = 'c' AND conrelid IN ('public.ntf_ping'::regclass, 'public.ntf_notification'::regclass, 'public.ntf_mute'::regclass, 'public.ntf_delivery'::regclass)",
            ),
        );
    }

    /**
     * @return iterable<string, array{non-empty-list<array{string, array<string, mixed>}>, string, string}>
     */
    public static function rowsTheDatabaseRefuses(): iterable
    {
        yield 'an empty ping' => [[self::ping(['body' => ''])], 'ntf_ping_body_check', self::CHECK_VIOLATION];
        yield 'a ping one character over 500' => [[self::ping(['body' => str_repeat('é', 501)])], 'ntf_ping_body_check', self::CHECK_VIOLATION];
        yield 'a ping cleared for no reason' => [[self::ping(['cleared_at' => '2027-03-15 10:45:00+00'])], 'ntf_ping_cleared_check', self::CHECK_VIOLATION];
        yield 'a ping with a reason but never cleared' => [[self::ping(['cleared_reason' => 'done'])], 'ntf_ping_cleared_check', self::CHECK_VIOLATION];
        yield 'a ping cleared for an unknown reason' => [[self::ping(['cleared_at' => '2027-03-15 10:45:00+00', 'cleared_reason' => 'snoozed'])], 'ntf_ping_cleared_reason_check', self::CHECK_VIOLATION];
        yield 'notification data that is a list' => [[self::notification(['data' => '["pinged"]'])], 'ntf_notification_data_check', self::CHECK_VIOLATION];
        yield 'notification data that is a string' => [[self::notification(['data' => '"pinged"'])], 'ntf_notification_data_check', self::CHECK_VIOLATION];
        yield 'a notification of an unknown kind' => [[self::notification(['kind' => 'pinged'])], 'ntf_notification_kind_check', self::CHECK_VIOLATION];
        yield 'a notification kind in another case' => [[self::notification(['kind' => 'Ping'])], 'ntf_notification_kind_check', self::CHECK_VIOLATION];
        yield 'a notification kind with a trailing space' => [[self::notification(['kind' => 'ping '])], 'ntf_notification_kind_check', self::CHECK_VIOLATION];
        yield 'a notification of no kind' => [[self::notification(['kind' => ''])], 'ntf_notification_kind_check', self::CHECK_VIOLATION];
        yield 'a delivery for no window' => [[self::delivery('')], 'ntf_delivery_window_key_check', self::CHECK_VIOLATION];
        yield 'a second delivery for one window' => [[self::delivery('digest:2027-03-15'), self::delivery('digest:2027-03-15')], 'ntf_delivery_pkey', self::UNIQUE_VIOLATION];
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
        yield 'a ping of exactly 500 characters' => [[self::ping(['body' => str_repeat('é', 500)])]];
        yield 'a ping cleared as done' => [[self::ping(['cleared_at' => '2027-03-15 10:45:00+00', 'cleared_reason' => 'done'])]];
        yield 'a ping cleared by acting' => [[self::ping(['cleared_at' => '2027-03-15 10:45:00+00', 'cleared_reason' => 'acted'])]];
        yield 'notification data that is an object' => [[self::notification(['data' => '{"pingId": "'.self::PING.'"}'])]];
        yield 'deliveries for two windows' => [[self::delivery('digest:2027-03-15'), self::delivery('digest:2027-03-16')]];
        foreach (self::DOCUMENTED_KINDS as $kind) {
            yield 'a notification of kind '.$kind => [[self::notification(['kind' => $kind])]];
        }
    }

    /**
     * The check is the closed set that lets pinround_resolver read kind in every organization (CatalogTest), so it holds
     * exactly the documented kinds, and every kind the code writes or the bell reads is one of them.
     */
    #[Test]
    public function theKindCheckAllowsExactlyTheDocumentedKinds(): void
    {
        $definition = $this->connection()->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'public.ntf_notification'::regclass AND conname = 'ntf_notification_kind_check' AND convalidated",
        );
        self::assertIsString($definition);
        self::assertSame(1, preg_match('/^CHECK \(\(kind = ANY \(ARRAY\[(.+)\]\)\)\)\z/', $definition, $match));
        $allowedKinds = array_map(static fn (string $literal): string => substr($literal, 1, -\strlen("'::text")), explode(', ', $match[1]));

        self::assertEqualsCanonicalizing(self::DOCUMENTED_KINDS, $allowedKinds);
        self::assertSame([], array_diff(self::kindsTheCodeWritesOrReads(), self::DOCUMENTED_KINDS));
    }

    /**
     * @return list<string>
     */
    private static function kindsTheCodeWritesOrReads(): array
    {
        return array_values(array_unique([
            ...array_map(static fn (NotificationKind $kind): string => $kind->value, NotificationKind::cases()),
            ...BellKind::codes(),
        ]));
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
     * @param array<string, mixed> $change the columns that differ from a valid open ping
     *
     * @return array{string, array<string, mixed>}
     */
    private static function ping(array $change): array
    {
        return ['ntf_ping', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::PING,
            'ticket_id' => self::TICKET,
            'from_id' => self::LENA,
            'to_id' => self::OMAR,
            'body' => 'Can you look at this today?',
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid notification
     *
     * @return array{string, array<string, mixed>}
     */
    private static function notification(array $change): array
    {
        return ['ntf_notification', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::NOTIFICATION,
            'user_id' => self::OMAR,
            'kind' => 'ping',
            'actor_id' => self::LENA,
            'ticket_id' => self::TICKET,
            'data' => '{}',
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function delivery(string $windowKey): array
    {
        return ['ntf_delivery', [
            'organization_id' => self::ACME_FOODS,
            'user_id' => self::OMAR,
            'window_key' => $windowKey,
            'sent_at' => '2027-03-15 07:00:00+00',
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
