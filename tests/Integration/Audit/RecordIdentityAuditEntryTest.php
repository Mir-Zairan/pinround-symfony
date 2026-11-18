<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\Application\RecordIdentityAuditEntry;
use App\Identity\Api\Event\ApiTokenIssued;
use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Infrastructure\Messenger\MessengerIdentityEvents;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The audit consumer of the token events on the real outbox, relay, worker and aud_event table: each event writes
 * exactly one row under the token's organization, with the token as target and only the allow-listed data, and a
 * redelivered event writes none.
 */
final class RecordIdentityAuditEntryTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000008b1';
    private const string OMARS_TOKEN = '0197a3c0-0000-7000-8000-0000000008c1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000008d1';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000008d2';
    // Inside the months the migrations created aud_event partitions for (2026-11 to 2027-12).
    private const string NOW = '2027-03-15 10:00:00 UTC';
    // Earlier than the worker's clock, as when the relay or a retry from failed delivers the event late.
    private const string ISSUED_AT = '2027-03-14 08:30:00 UTC';
    private const string REVOKED_AT = '2027-03-14 09:45:00 UTC';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->migrator()->executeStatement('TRUNCATE aud_event');
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordIdentityAuditEntry::HANDLER_NAME]);
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->migrator()->executeStatement('TRUNCATE aud_event');
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [RecordIdentityAuditEntry::HANDLER_NAME]);
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        parent::tearDown();
    }

    #[Test]
    public function anIssuedAndARevokedTokenWriteOneRowEachAboutTheTokenWithOnlyTheirAllowListedData(): void
    {
        $this->publishIssuedAndRevokedByAnAdmin();

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        $rows = $this->auditRows();
        self::assertSame(
            [
                ['user', self::OMAR, 'api_token.issued', 'api_token', self::OMARS_TOKEN, '2027-03-14 08:30:00'],
                ['user', self::SARA, 'api_token.revoked', 'api_token', self::OMARS_TOKEN, '2027-03-14 09:45:00'],
            ],
            array_map(static fn (array $row): array => \array_slice($row, 0, 6), $rows),
        );
        // assertEquals: jsonb keeps the keys, not their order.
        self::assertEquals([['scopes' => ['read', 'rounds:write'], 'expires_at' => '2027-04-13T08:30:00+00:00'], []], array_column($rows, 6));
    }

    #[Test]
    public function aRedeliveredTokenEventWritesNoSecondRow(): void
    {
        $this->publishIssuedAndRevokedByAnAdmin();
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        $asyncNotify = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $asyncNotify);
        $issued = array_values(array_filter($asyncNotify->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof ApiTokenIssued));
        self::assertCount(1, $issued);

        $asyncNotify->send($issued[0]);
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(3, $asyncNotify->getSent());
        self::assertSame(['api_token.issued', 'api_token.revoked'], array_column($this->auditRows(), 2));
    }

    private function publishIssuedAndRevokedByAnAdmin(): void
    {
        $events = new MessengerIdentityEvents(self::getContainer()->get('event.bus'), $this->entityManager()->getConnection());
        $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $events->publish(new ApiTokenIssued(
            self::ACME_FOODS,
            self::OMARS_TOKEN,
            self::OMAR,
            ['read', 'rounds:write'],
            new \DateTimeImmutable('2027-04-13 08:30:00 UTC'),
            self::OMAR,
            new \DateTimeImmutable(self::ISSUED_AT),
        )));
        $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $events->publish(new ApiTokenRevoked(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, self::SARA, new \DateTimeImmutable(self::REVOKED_AT))));
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}> actor kind, actor, action, target type, target, time, data; in time order
     */
    private function auditRows(): array
    {
        $rows = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->entityManager()->getConnection()->fetchAllNumeric(
            "SELECT actor_kind, actor_user_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text
             FROM aud_event WHERE organization_id = ? ORDER BY at",
            [self::ACME_FOODS],
        ));

        return array_map(static function (array $row): array {
            self::assertIsString($row[6]);
            $data = json_decode($row[6], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $data];
        }, $rows);
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}
