<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Api\DemoTurnWaits;
use App\SharedKernel\Domain\TicketId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalDemoTurnWaits through the container. DemoSeedCommandTest proves the seed's turns wait as long as their tickets
 * last moved.
 */
final class DbalDemoTurnWaitsTest extends KernelTestCase
{
    #[Test]
    public function itRefusesOutsideATransactionAndWritesNothing(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertFalse($connection->isTransactionActive());

        try {
            self::getContainer()->get(DemoTurnWaits::class)->backdate(new TicketId('0197a3c0-0000-7000-8000-0000000019e1'), new \DateTimeImmutable('2027-03-01 10:00:00+00:00'));
            self::fail('A backdate outside a transaction must refuse.');
        } catch (\LogicException $refusal) {
            self::assertSame('A turn wait is set in the seed\'s transaction; none is open.', $refusal->getMessage());
        }
        self::assertFalse($connection->isTransactionActive());
    }
}
