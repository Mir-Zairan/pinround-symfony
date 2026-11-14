<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Health;

use App\Notification\Infrastructure\Doctrine\NotificationPartitions;
use App\Notification\Infrastructure\Health\NextNotificationPartitionExists;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * On the migrated test database at a clock years past the migration's months, so next month has no partition until
 * a test adds one in a transaction it never commits; the check reads the catalog on that same connection.
 */
final class NextNotificationPartitionExistsTest extends KernelTestCase
{
    private const string NOW = '2031-06-15 10:00:00 UTC';

    #[Test]
    public function itFailsWhenNextMonthsPartitionIsMissing(): void
    {
        $check = new NextNotificationPartitionExists($this->connection(), new MockClock(self::NOW));
        self::assertSame('next_notification_partition', $check->name());

        $this->expectException(ReadinessCheckFailed::class);
        $this->expectExceptionMessageIsOrContains('ntf_notification has no partition for next month.');

        $check->verify();
    }

    #[Test]
    public function itPassesWithinTheMigratedMonths(): void
    {
        $this->expectNotToPerformAssertions();

        new NextNotificationPartitionExists($this->connection(), new MockClock('2027-03-15 10:00:00 UTC'))->verify();
    }

    #[Test]
    public function aPartitionAddedForNextMonthMakesTheCheckPass(): void
    {
        $migrator = $this->migratorConnection();
        $migrator->beginTransaction();

        try {
            foreach (NotificationPartitions::statementsFor(new \DateTimeImmutable('2031-07-01 UTC')) as $statement) {
                $migrator->executeStatement($statement);
            }

            new NextNotificationPartitionExists($migrator, new MockClock(self::NOW))->verify();
        } finally {
            $migrator->rollBack();
        }

        self::assertNull($migrator->fetchOne("SELECT to_regclass('public.ntf_notification_203107')"), 'The partition was committed.');
    }

    #[Test]
    public function onlyThisMonthsPartitionDoesNotCount(): void
    {
        $migrator = $this->migratorConnection();
        $migrator->beginTransaction();

        try {
            foreach (NotificationPartitions::statementsFor(new \DateTimeImmutable(self::NOW)) as $statement) {
                $migrator->executeStatement($statement);
            }
            $this->expectException(ReadinessCheckFailed::class);

            new NextNotificationPartitionExists($migrator, new MockClock(self::NOW))->verify();
        } finally {
            $migrator->rollBack();
        }
    }

    #[Test]
    public function aTableNamedLikeAPartitionButNotAttachedDoesNotCount(): void
    {
        $migrator = $this->migratorConnection();
        $migrator->beginTransaction();

        try {
            $migrator->executeStatement('CREATE TABLE ntf_notification_203107 (id INT)');
            $this->expectException(ReadinessCheckFailed::class);

            new NextNotificationPartitionExists($migrator, new MockClock(self::NOW))->verify();
        } finally {
            $migrator->rollBack();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migratorConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
