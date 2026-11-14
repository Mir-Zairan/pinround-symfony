<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Infrastructure\Doctrine\DbalDeliveries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\DeliveryRows;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Digest deliveries through DbalDeliveries as pinround_app under row-level security in Acme Foods on 3 March 2026.
 * Omar got the 06:00 and 09:00 digests and, later, a delivery that is not a digest; Mia got only one that is not a
 * digest. Omar's later digest in Beta Bakery must never count for Acme Foods.
 */
final class DbalDeliveriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000d1101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000d1102';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000d1131';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000d1133';
    private const string EARLY_WINDOW = 'digest:2026-03-03T06:00:00Z';
    private const string MORNING_WINDOW = 'digest:2026-03-03T09:00:00Z';
    private const string NOON_WINDOW = 'digest:2026-03-03T12:00:00Z';
    private const string NOT_A_DIGEST = 'welcome:2026-03-03';
    private const string NOW = '2026-03-03 12:05:00+00:00';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_delivery', [
            self::delivery(self::ACME_FOODS, self::OMAR, self::MORNING_WINDOW, '2026-03-03 09:00:05.123456+00'),
            self::delivery(self::ACME_FOODS, self::OMAR, self::EARLY_WINDOW, '2026-03-03 06:00:04+00'),
            self::delivery(self::ACME_FOODS, self::OMAR, self::NOT_A_DIGEST, '2026-03-03 11:00:00+00'),
            self::delivery(self::ACME_FOODS, self::MIA, self::NOT_A_DIGEST, '2026-03-03 11:00:00+00'),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_delivery', [
            self::delivery(self::BETA_BAKERY, self::OMAR, self::NOON_WINDOW, '2026-03-03 12:00:02+00'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function theLastDigestIsThePersonsLatestDigestInTheTenantsOrganization(): void
    {
        $sentAt = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->deliveries()->lastDigestSentAt(new UserId(self::OMAR)));

        self::assertSame('2026-03-03T09:00:05.123456+00:00', $sentAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function aPersonWithOnlyOtherDeliveriesHasNoLastDigest(): void
    {
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?\DateTimeImmutable => $this->deliveries()->lastDigestSentAt(new UserId(self::MIA))));
    }

    #[Test]
    public function aDigestIsRecordedOnceForThePersonAndWindow(): void
    {
        $deliveries = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::assertTrue($this->deliveries()->recordDigest(new UserId(self::OMAR), self::NOON_WINDOW, new \DateTimeImmutable(self::NOW)));
            self::assertSame('2026-03-03 12:05:00', $this->deliveries()->lastDigestSentAt(new UserId(self::OMAR))?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

            return $this->deliveriesOf(self::ACME_FOODS);
        });

        self::assertSame([...$this->seededAcmeFoodsDeliveries(), [self::OMAR, self::NOON_WINDOW, '2026-03-03 12:05:00']], $deliveries);
    }

    #[Test]
    public function aSecondDigestForTheSameWindowIsRefusedAndKeepsTheFirst(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->deliveries()->recordDigest(new UserId(self::MIA), self::NOON_WINDOW, new \DateTimeImmutable(self::NOW)));

        $deliveries = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::assertFalse($this->deliveries()->recordDigest(new UserId(self::MIA), self::NOON_WINDOW, new \DateTimeImmutable('2026-03-03 12:20:00+00:00')));

            return $this->deliveriesOf(self::ACME_FOODS);
        });

        self::assertSame([...$this->seededAcmeFoodsDeliveries(), [self::MIA, self::NOON_WINDOW, '2026-03-03 12:05:00']], $deliveries);
    }

    #[Test]
    public function anotherPersonsDigestForTheSameWindowIsRecorded(): void
    {
        self::assertTrue($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): bool => $this->deliveries()->recordDigest(new UserId(self::MIA), self::MORNING_WINDOW, new \DateTimeImmutable(self::NOW))));
    }

    /**
     * The SQL names Acme Foods while the transaction's tenant is Beta Bakery, so only row-level security keeps Omar's
     * Acme Foods digests from the read and refuses a digest in Acme Foods' name; and Omar's Beta Bakery digest for the
     * noon window does not keep Acme Foods from recording one for it.
     */
    #[Test]
    public function anotherOrganizationsDeliveriesAreNeitherSeenNorInTheWay(): void
    {
        $lastDigestOfOmar = fn (Connection $connection): ?\DateTimeImmutable => $this->deliveriesOn($connection)->lastDigestSentAt(new UserId(self::OMAR));
        self::assertNotNull($this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $lastDigestOfOmar));
        self::assertNull($this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $lastDigestOfOmar));

        try {
            $this->ownTransactions()->rolledBackUnderAnotherContext(
                self::BETA_BAKERY,
                self::ACME_FOODS,
                fn (Connection $connection): bool => $this->deliveriesOn($connection)->recordDigest(new UserId(self::MIA), self::NOON_WINDOW, new \DateTimeImmutable(self::NOW)),
            );
            self::fail('Row-level security did not refuse a digest in the name of another organization.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "ntf_delivery"', $refusal->getMessage());
        }

        self::assertTrue($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): bool => $this->deliveries()->recordDigest(new UserId(self::OMAR), self::NOON_WINDOW, new \DateTimeImmutable(self::NOW))));
        self::assertSame($this->seededAcmeFoodsDeliveries(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->deliveriesOf(self::ACME_FOODS)));
    }

    #[Test]
    #[TestWith(['digest:'])]
    #[TestWith(['2026-03-03T12:00:00Z'])]
    #[TestWith(['Digest:2026-03-03T12:00:00Z'])]
    public function aWindowKeyThatIsNotADigestsIsRefusedBeforeAnyQuery(string $windowKey): void
    {
        $deliveries = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($windowKey): array {
            try {
                $this->deliveries()->recordDigest(new UserId(self::MIA), $windowKey, new \DateTimeImmutable(self::NOW));
                self::fail('A window key that is not a digest\'s was not refused.');
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame('A digest window key starts with "digest:" and names the window.', $refusal->getMessage());
            }

            return $this->deliveriesOf(self::ACME_FOODS);
        });

        self::assertSame($this->seededAcmeFoodsDeliveries(), $deliveries);
    }

    #[Test]
    public function aReadOrRecordWithoutATenantIsRefusedBeforeAnyQuery(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();

        try {
            $this->deliveries()->recordDigest(new UserId(self::MIA), self::NOON_WINDOW, new \DateTimeImmutable(self::NOW));
            self::fail('A digest without a tenant was not refused.');
        } catch (TenantNotSet) {
        }
        try {
            $this->deliveries()->lastDigestSentAt(new UserId(self::OMAR));
            self::fail('A read without a tenant was not refused.');
        } catch (TenantNotSet) {
        }

        self::assertSame($this->seededAcmeFoodsDeliveries(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->deliveriesOf(self::ACME_FOODS)));
    }

    /**
     * Outside a transaction row-level security would hide Omar's digests, and a null would let a second digest go out.
     */
    #[Test]
    public function aReadOrRecordOutsideATransactionIsRefused(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            foreach ([
                fn (): ?\DateTimeImmutable => $this->deliveries()->lastDigestSentAt(new UserId(self::OMAR)),
                fn (): bool => $this->deliveries()->recordDigest(new UserId(self::MIA), self::NOON_WINDOW, new \DateTimeImmutable(self::NOW)),
            ] as $work) {
                try {
                    $work();
                    self::fail('A digest delivery outside a transaction was not refused.');
                } catch (\LogicException $refusal) {
                    self::assertSame('A digest delivery is read and recorded in a transaction; none is open.', $refusal->getMessage());
                }
            }
        } finally {
            $tenantContext->reset();
        }

        self::assertSame($this->seededAcmeFoodsDeliveries(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->deliveriesOf(self::ACME_FOODS)));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function seededAcmeFoodsDeliveries(): array
    {
        return [
            [self::OMAR, self::EARLY_WINDOW, '2026-03-03 06:00:04'],
            [self::OMAR, self::MORNING_WINDOW, '2026-03-03 09:00:05'],
            [self::OMAR, self::NOT_A_DIGEST, '2026-03-03 11:00:00'],
            [self::MIA, self::NOT_A_DIGEST, '2026-03-03 11:00:00'],
        ];
    }

    /**
     * @return list<array{string, string, string}> each delivery's person, window and UTC time, by time, then person
     */
    private function deliveriesOf(string $organizationId): array
    {
        $rows = $this->connection()->fetchAllNumeric(
            "SELECT user_id::text, window_key, to_char(sent_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')"
            .' FROM ntf_delivery WHERE organization_id = ? ORDER BY sent_at, user_id, window_key',
            [$organizationId],
        );

        return array_map(static function (array $row): array {
            [$userId, $windowKey, $sentAt] = $row;
            self::assertIsString($userId);
            self::assertIsString($windowKey);
            self::assertIsString($sentAt);

            return [$userId, $windowKey, $sentAt];
        }, $rows);
    }

    private function deliveries(): DbalDeliveries
    {
        return $this->deliveriesOn($this->connection());
    }

    private function deliveriesOn(Connection $connection): DbalDeliveries
    {
        return new DbalDeliveries($connection, self::getContainer()->get(TenantContext::class));
    }

    /**
     * @return array<string, string>
     */
    private static function delivery(string $organizationId, string $userId, string $windowKey, string $sentAt): array
    {
        return ['organization_id' => $organizationId, 'user_id' => $userId, 'window_key' => $windowKey, 'sent_at' => $sentAt];
    }

    private function removeRows(): void
    {
        new DeliveryRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class))
            ->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }

    private function ownTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
