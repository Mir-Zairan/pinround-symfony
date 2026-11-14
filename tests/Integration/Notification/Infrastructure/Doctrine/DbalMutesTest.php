<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Infrastructure\Doctrine\DbalMutes;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Muting and unmuting through DbalMutes as pinround_app under row-level security in Acme Foods. Omar muted SHOP-1 on
 * 18 March 2027 and Mia muted SHOP-1 and SHOP-2; Omar's mute in Beta Bakery must stay whatever Acme Foods does.
 */
final class DbalMutesTest extends KernelTestCase
{
    private const string MUTED_AT = '2027-03-18 09:00:00+00';
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b5101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000b5102';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b5111';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000b5112';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000b5121';
    private const string SHOP_2 = '0197a3c0-0000-7000-8000-0000000b5122';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000b5123';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b5131';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b5132';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000b5133';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_1, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_2, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_mute', [
            self::mute(self::ACME_FOODS, self::OMAR, self::SHOP_1),
            self::mute(self::ACME_FOODS, self::MIA, self::SHOP_1),
            self::mute(self::ACME_FOODS, self::MIA, self::SHOP_2),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_mute', [
            self::mute(self::BETA_BAKERY, self::OMAR, self::BAKERY_TICKET),
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aMuteStoresOneRowForThePersonAndTicketAtTheTime(): void
    {
        $mutes = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::assertTrue($this->mutes()->mute(new UserId(self::LENA), new TicketId(self::SHOP_2), new \DateTimeImmutable(self::NOW)));

            return $this->mutesInAcmeFoods();
        });

        self::assertSame([...$this->seededAcmeFoodsMutes(), [self::LENA, self::SHOP_2, '2027-03-20 10:00:00+00']], $mutes);
    }

    #[Test]
    public function aSecondMuteKeepsTheFirstAndSaysNothingChanged(): void
    {
        $mutes = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::assertFalse($this->mutes()->mute(new UserId(self::OMAR), new TicketId(self::SHOP_1), new \DateTimeImmutable(self::NOW)));

            return $this->mutesInAcmeFoods();
        });

        self::assertSame($this->seededAcmeFoodsMutes(), $mutes);
    }

    #[Test]
    public function anUnmuteRemovesOnlyThatPersonsMuteOfThatTicket(): void
    {
        $mutes = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::assertTrue($this->mutes()->unmute(new UserId(self::MIA), new TicketId(self::SHOP_1)));

            return $this->mutesInAcmeFoods();
        });

        self::assertSame([
            [self::OMAR, self::SHOP_1, '2027-03-18 09:00:00+00'],
            [self::MIA, self::SHOP_2, '2027-03-18 09:00:00+00'],
        ], $mutes);
    }

    #[Test]
    public function anUnmuteOfATicketThePersonDidNotMuteChangesNothing(): void
    {
        $mutes = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::assertFalse($this->mutes()->unmute(new UserId(self::OMAR), new TicketId(self::SHOP_2)));

            return $this->mutesInAcmeFoods();
        });

        self::assertSame($this->seededAcmeFoodsMutes(), $mutes);
    }

    #[Test]
    public function anUnmuteInAcmeFoodsLeavesTheSamePersonsMuteInBetaBakery(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->mutes()->unmute(new UserId(self::OMAR), new TicketId(self::BAKERY_TICKET)));

        self::assertSame(
            [[self::OMAR, self::BAKERY_TICKET, '2027-03-18 09:00:00+00']],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->mutesOf(self::BETA_BAKERY)),
        );
    }

    /**
     * The SQL names Acme Foods while the transaction's tenant is Beta Bakery, so only row-level security keeps Omar's
     * Acme Foods mute from the delete and refuses a mute in Acme Foods' name.
     */
    #[Test]
    public function rowLevelSecurityAloneKeepsAnotherOrganizationsMutesFromAChange(): void
    {
        $unmuteOmar = fn (Connection $connection): bool => $this->mutesOn($connection)->unmute(new UserId(self::OMAR), new TicketId(self::SHOP_1));
        self::assertTrue($this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $unmuteOmar));
        self::assertFalse($this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $unmuteOmar));

        try {
            $this->ownTransactions()->rolledBackUnderAnotherContext(
                self::BETA_BAKERY,
                self::ACME_FOODS,
                fn (Connection $connection): bool => $this->mutesOn($connection)->mute(new UserId(self::LENA), new TicketId(self::SHOP_2), new \DateTimeImmutable(self::NOW)),
            );
            self::fail('Row-level security did not refuse a mute in the name of another organization.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "ntf_mute"', $refusal->getMessage());
        }

        self::assertSame($this->seededAcmeFoodsMutes(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->mutesInAcmeFoods()));
    }

    #[Test]
    public function aChangeWithoutATenantIsRefusedBeforeAnyQuery(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();

        try {
            $this->mutes()->unmute(new UserId(self::OMAR), new TicketId(self::SHOP_1));
            self::fail('An unmute without a tenant was not refused.');
        } catch (TenantNotSet) {
        }

        self::assertSame($this->seededAcmeFoodsMutes(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->mutesInAcmeFoods()));
    }

    #[Test]
    public function aChangeOutsideATransactionIsRefused(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->mutes()->unmute(new UserId(self::OMAR), new TicketId(self::SHOP_1));
            self::fail('An unmute outside a transaction was not refused.');
        } catch (\LogicException $refusal) {
            self::assertSame('A mute changes in the transaction of the request; none is open.', $refusal->getMessage());
        } finally {
            $tenantContext->reset();
        }

        self::assertSame($this->seededAcmeFoodsMutes(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->mutesInAcmeFoods()));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function seededAcmeFoodsMutes(): array
    {
        return [
            [self::OMAR, self::SHOP_1, '2027-03-18 09:00:00+00'],
            [self::MIA, self::SHOP_1, '2027-03-18 09:00:00+00'],
            [self::MIA, self::SHOP_2, '2027-03-18 09:00:00+00'],
        ];
    }

    /**
     * @return list<array{string, string, string}> each mute's person, ticket and time, by time, then person and ticket
     */
    private function mutesInAcmeFoods(): array
    {
        return $this->mutesOf(self::ACME_FOODS);
    }

    /**
     * @return list<array{string, string, string}> each mute's person, ticket and time, by time, then person and ticket
     */
    private function mutesOf(string $organizationId): array
    {
        $rows = $this->connection()->fetchAllNumeric(
            "SELECT user_id::text, ticket_id::text, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00'"
            .' FROM ntf_mute WHERE organization_id = ? ORDER BY created_at, user_id, ticket_id',
            [$organizationId],
        );

        return array_map(static function (array $row): array {
            [$userId, $ticketId, $createdAt] = $row;
            self::assertIsString($userId);
            self::assertIsString($ticketId);
            self::assertIsString($createdAt);

            return [$userId, $ticketId, $createdAt];
        }, $rows);
    }

    private function mutes(): DbalMutes
    {
        return $this->mutesOn($this->connection());
    }

    private function mutesOn(Connection $connection): DbalMutes
    {
        return new DbalMutes($connection, self::getContainer()->get(TenantContext::class));
    }

    /**
     * @return array<string, string>
     */
    private static function mute(string $organizationId, string $userId, string $ticketId): array
    {
        return ['organization_id' => $organizationId, 'user_id' => $userId, 'ticket_id' => $ticketId, 'created_at' => self::MUTED_AT];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
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

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
