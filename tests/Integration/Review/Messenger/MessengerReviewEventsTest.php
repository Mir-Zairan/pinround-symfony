<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Messenger;

use App\Review\Api\Event\PinVerdictUndone;
use App\Review\Infrastructure\Messenger\MessengerReviewEvents;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Other tests write to the same outbox at the same time, so rows are counted by this test's own pin id.
 */
final class MessengerReviewEventsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f7e01';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f7e02';
    private const string ROUND = '0197a3c0-0000-7000-8000-0000000f7e03';
    private const string PIN = '0197a3c0-0000-7000-8000-0000000f7e04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7e05';

    protected function tearDown(): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.self::PIN.'%']);
        parent::tearDown();
    }

    #[Test]
    public function aReviewEventGoesToTheOutboxInTheOpenTransactionAndLeavesWithItsRollback(): void
    {
        $rowsInTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->reviewEvents()->publish($this->pinVerdictUndone());

            return $this->outboxRowsOfThePin();
        });

        self::assertSame([1, 0], [$rowsInTransaction, $this->outboxRowsOfThePin()]);
    }

    #[Test]
    public function aReviewEventWithNoTransactionOpenIsRefusedAndWritesNothing(): void
    {
        self::assertFalse($this->connection()->isTransactionActive());

        try {
            $this->reviewEvents()->publish($this->pinVerdictUndone());
            self::fail('A review event was published with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('A review event is published in the transaction of its change; none is open.', $refusal->getMessage());
        }

        self::assertSame(0, $this->outboxRowsOfThePin());
    }

    private function pinVerdictUndone(): PinVerdictUndone
    {
        return new PinVerdictUndone(self::ACME_FOODS, self::TICKET, self::ROUND, self::PIN, 'fixed', self::MIA, new \DateTimeImmutable('2027-03-16 09:45:00+00:00'));
    }

    private function outboxRowsOfThePin(): int
    {
        $rows = $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.self::PIN.'%']);
        self::assertIsInt($rows);

        return $rows;
    }

    private function reviewEvents(): MessengerReviewEvents
    {
        return new MessengerReviewEvents(self::getContainer()->get('event.bus'), $this->connection());
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
