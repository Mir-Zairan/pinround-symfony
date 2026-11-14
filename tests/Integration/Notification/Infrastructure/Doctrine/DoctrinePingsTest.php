<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\PingId;
use App\Notification\Infrastructure\Doctrine\DoctrinePings;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrinePingsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000005201';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-000000005202';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000005203';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000005204';
    private const string LENA = '0197a3c0-0000-7000-8000-000000005205';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000005206';
    private const string UNKNOWN_PING = '0197a3c0-0000-7000-8000-000000005207';

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
    public function anAddedPingIsOneRowOfItsOrganizationOnly(): void
    {
        $pingId = $this->transactions()->committedAs(self::ACME_FOODS, function (): string {
            $ping = $this->ping();
            $this->pings()->add($ping);

            return $ping->id()->toString();
        });

        self::assertSame(
            [['id' => $pingId, 'organization_id' => self::ACME_FOODS, 'body' => 'Any news?']],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT id, organization_id, body FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])),
        );
        self::assertSame(0, $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])));
    }

    #[Test]
    public function addingAPingOutsideATransactionIsRefusedAndStoresNothing(): void
    {
        try {
            $this->pings()->add($this->ping());
            self::fail('A ping was added outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Adding a ping needs an open transaction.', $refusal->getMessage());
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])));
    }

    #[Test]
    public function savingAPingOutsideATransactionIsRefusedAndLeavesItsRowAsItWas(): void
    {
        $ping = $this->transactions()->committedAs(self::ACME_FOODS, function (): Ping {
            $ping = $this->ping();
            $this->pings()->add($ping);

            return $ping;
        });
        $ping->clear(PingClearedReason::Acted, new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));

        try {
            $this->pings()->save($ping);
            self::fail('A ping was saved outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Saving a ping needs an open transaction.', $refusal->getMessage());
        }

        self::assertSame([['id' => $ping->id()->toString(), 'cleared_at' => null, 'cleared_reason' => null]], $this->storedPings());
    }

    #[Test]
    public function lockingPingsOutsideATransactionIsRefusedAndLeavesThemOpen(): void
    {
        $pingId = $this->transactions()->committedAs(self::ACME_FOODS, function (): string {
            $ping = $this->ping();
            $this->pings()->add($ping);

            return $ping->id()->toString();
        });

        try {
            $this->pings()->lockedOpenPingsTo(new UserId(self::OMAR), new TicketId(self::TICKET));
            self::fail('Pings were locked outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Locking pings needs an open transaction.', $refusal->getMessage());
        }

        self::assertSame([['id' => $pingId, 'cleared_at' => null, 'cleared_reason' => null]], $this->storedPings());
    }

    #[Test]
    public function theLockedOpenPingIsReadFreshUnderItsOwnOrganizationOnly(): void
    {
        $pingId = $this->addedPingId();

        $lockedPing = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($pingId): ?array {
            $ping = $this->pings()->lockedOpenPing($pingId);

            return null === $ping ? null : [$ping->id()->toString(), $ping->body(), $ping->deliverAfter()?->format(\DATE_ATOM)];
        });

        self::assertSame([$pingId->toString(), 'Any news?', '2027-03-16T08:00:00+00:00'], $lockedPing);
        self::assertNull($this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): ?Ping => $this->pings()->lockedOpenPing($pingId)));
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Ping => $this->pings()->lockedOpenPing(new PingId(self::UNKNOWN_PING))));
    }

    #[Test]
    public function aClearedPingIsNoLockedOpenPing(): void
    {
        $pingId = $this->addedPingId();
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($pingId): void {
            $ping = $this->pings()->lockedOpenPing($pingId) ?? throw new \LogicException('The ping was not found open.');
            $ping->clear(PingClearedReason::Done, new \DateTimeImmutable('2027-03-15 11:00:00+00:00'));
            $this->pings()->save($ping);
        });

        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Ping => $this->pings()->lockedOpenPing($pingId)));
    }

    #[Test]
    public function theLockedOpenPingKeepsAClearingWaitingButNotAReader(): void
    {
        $pingId = $this->addedPingId();

        [$readerLock, $clearingLockFailure] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($pingId): array {
            $this->pings()->lockedOpenPing($pingId);
            // Another session under the same tenant, which its transaction start sets, as FORCE ROW LEVEL SECURITY
            // applies to it too.
            $otherSession = self::getContainer()->get('doctrine.dbal.migrator_connection');
            $readerLock = $otherSession->transactional(static fn (Connection $session): mixed => $session->fetchOne('SELECT id FROM ntf_ping WHERE id = ? FOR SHARE NOWAIT', [$pingId->toString()]));
            try {
                $otherSession->transactional(static fn (Connection $session): mixed => $session->fetchOne('SELECT id FROM ntf_ping WHERE id = ? FOR UPDATE NOWAIT', [$pingId->toString()]));

                return [$readerLock, null];
            } catch (DriverException $failure) {
                return [$readerLock, $failure->getSQLState()];
            }
        });

        self::assertSame([$pingId->toString(), '55P03'], [$readerLock, $clearingLockFailure]);
    }

    #[Test]
    public function lockingOnePingOutsideATransactionIsRefusedBeforeAnyRead(): void
    {
        $pingId = $this->addedPingId();

        try {
            $this->pings()->lockedOpenPing($pingId);
            self::fail('A ping was locked outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Locking a ping needs an open transaction.', $refusal->getMessage());
        }
    }

    private function addedPingId(): PingId
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, function (): PingId {
            $ping = Ping::send($this->pings()->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Any news?', new \DateTimeImmutable('2027-03-16 08:00:00+00:00'), new \DateTimeImmutable('2027-03-15 21:00:00+00:00'));
            $this->pings()->add($ping);

            return $ping->id();
        });
    }

    /**
     * @return list<array<string, mixed>> the ticket's pings in Acme Foods with their cleared state
     */
    private function storedPings(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT id, cleared_at, cleared_reason FROM ntf_ping WHERE ticket_id = ? ORDER BY id', [self::TICKET]));
    }

    private function ping(): Ping
    {
        return Ping::send($this->pings()->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), ' Any news? ', null, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    private function pings(): DoctrinePings
    {
        return new DoctrinePings($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
