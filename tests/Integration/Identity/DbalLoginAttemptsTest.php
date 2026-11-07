<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Doctrine\DbalLoginAttempts;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * DbalLoginAttempts on the real idn_login_attempt table as pinround_app, which may only insert and read it. Every
 * test runs in a transaction that is rolled back, so no row is left behind.
 */
final class DbalLoginAttemptsTest extends KernelTestCase
{
    // The microseconds show that at and the UUIDv7 id keep the clock's instant to the microsecond.
    private const string NOW = '2027-03-15 10:00:00.123456 UTC';

    protected function tearDown(): void
    {
        if (self::$booted && $this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        parent::tearDown();
    }

    #[Test]
    public function aRecordedTryKeepsItsHashesItsSuccessFlagAndTheClocksInstantInAtAndInTheIdsTime(): void
    {
        $emailHash = random_bytes(32);
        $ipHash = random_bytes(32);
        $this->connection()->beginTransaction();

        $this->attempts()->record($emailHash, $ipHash, true);

        $rows = $this->connection()->fetchAllAssociative(
            "SELECT id::text AS id, email_hash, ip_hash, success, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US') AS at"
            .' FROM idn_login_attempt WHERE ip_hash = ?',
            [$ipHash],
            [ParameterType::BINARY],
        );
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('2027-03-15T10:00:00.123456', $row['at']);
        $idText = $this->asString($row['id']);
        $id = new UuidV7($idText);
        self::assertSame('2027-03-15T10:00:00.123456', $id->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u'));
        self::assertSame('7', $idText[14], 'a UUIDv7');
        $storedEmailHash = $this->asString($row['email_hash']);
        $storedIpHash = $this->asString($row['ip_hash']);
        self::assertSame(32, \strlen($storedEmailHash));
        self::assertSame(32, \strlen($storedIpHash));
        self::assertSame($emailHash, $storedEmailHash);
        self::assertSame($ipHash, $storedIpHash);
        self::assertTrue($row['success']);
    }

    #[Test]
    public function aFailedTryIsStoredWithSuccessFalse(): void
    {
        $ipHash = random_bytes(32);
        $this->connection()->beginTransaction();

        $this->attempts()->record(random_bytes(32), $ipHash, false);

        self::assertFalse($this->connection()->fetchOne('SELECT success FROM idn_login_attempt WHERE ip_hash = ?', [$ipHash], [ParameterType::BINARY]));
    }

    #[Test]
    public function aTryRecordedInATransactionThatRollsBackLeavesNoRow(): void
    {
        $ipHash = random_bytes(32);
        $this->connection()->beginTransaction();
        $this->attempts()->record(random_bytes(32), $ipHash, false);
        self::assertSame(1, $this->countRowsOf($ipHash), 'visible inside the transaction');

        $this->connection()->rollBack();

        self::assertSame(0, $this->countRowsOf($ipHash));
    }

    private function countRowsOf(string $ipHash): int
    {
        $count = $this->connection()->fetchOne('SELECT count(*) FROM idn_login_attempt WHERE ip_hash = ?', [$ipHash], [ParameterType::BINARY]);
        self::assertIsInt($count);

        return $count;
    }

    private function asString(mixed $value): string
    {
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }
        self::assertIsString($value);

        return $value;
    }

    private function attempts(): DbalLoginAttempts
    {
        return new DbalLoginAttempts($this->connection(), new MockClock(self::NOW));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
