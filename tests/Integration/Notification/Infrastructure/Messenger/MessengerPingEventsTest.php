<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Messenger;

use App\Notification\Api\Event\PingSent;
use App\Notification\Infrastructure\Messenger\MessengerPingEvents;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Other tests write to the same outbox at the same time, so rows are counted by this test's own ping id.
 */
final class MessengerPingEventsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000005301';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000005302';
    private const string PING = '0197a3c0-0000-7000-8000-000000005303';
    private const string LENA = '0197a3c0-0000-7000-8000-000000005304';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000005305';

    #[Test]
    public function aPingEventGoesToTheOutboxInTheOpenTransactionAndLeavesWithItsRollback(): void
    {
        $rowsInTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->pingEvents()->publish($this->pingSent());

            return $this->outboxRowsOfThePing();
        });

        self::assertSame([1, 0], [$rowsInTransaction, $this->outboxRowsOfThePing()]);
    }

    #[Test]
    public function aPingEventWithNoTransactionOpenIsRefusedAndWritesNothing(): void
    {
        try {
            $this->pingEvents()->publish($this->pingSent());
            self::fail('A ping event was published with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('A ping event is published in the transaction of its change; none is open.', $refusal->getMessage());
        }

        self::assertSame(0, $this->outboxRowsOfThePing());
    }

    private function pingSent(): PingSent
    {
        return new PingSent(self::ACME_FOODS, self::TICKET, self::PING, self::LENA, self::OMAR, self::LENA, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    private function outboxRowsOfThePing(): int
    {
        $rows = 0;
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox'") as $body) {
            self::assertIsString($body);
            if (str_contains($body, self::PING)) {
                ++$rows;
            }
        }

        return $rows;
    }

    private function pingEvents(): MessengerPingEvents
    {
        return new MessengerPingEvents(self::getContainer()->get('event.bus'), $this->connection());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
