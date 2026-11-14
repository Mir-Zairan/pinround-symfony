<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\UI;

use App\Notification\Application\Ping\RecentPingQueries;
use App\Notification\Application\Ping\RecentPings;
use App\Notification\UI\PingedState;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PingedStateTest extends TestCase
{
    private const string NOW = '2027-03-15 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c5101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c5102';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c5111';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c5112';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000c5121';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000c5122';

    private bool $isTransactionOpen = false;

    /** @var list<array{string, string, string, string, bool}> organization, sender, ticket, at and whether a transaction was open, per read */
    private array $reads = [];

    private int $transactionRuns = 0;

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function waits(): iterable
    {
        yield 'under a minute, rounded up' => [59, 'again in 1 min'];
        yield 'exactly a minute' => [60, 'again in 1 min'];
        yield 'a second over a minute' => [61, 'again in 2 min'];
        yield 'a second under an hour' => [3599, 'again in 60 min'];
        yield 'exactly an hour' => [3600, 'again in 1 h'];
        yield 'a second over an hour, rounded up' => [3601, 'again in 2 h'];
        yield 'the whole limit of 24 hours' => [86400, 'again in 24 h'];
        yield 'now' => [0, 'again in 1 min'];
        yield 'already passed' => [-90, 'again in 1 min'];
    }

    #[Test]
    #[DataProvider('waits')]
    public function theWaitIsShownInMinutesUnderAnHourAndInHoursFromAnHourRoundedUp(int $secondsLeft, string $againIn): void
    {
        $at = new \DateTimeImmutable(self::NOW);

        self::assertSame($againIn, PingedState::againInOf($at->modify(\sprintf('%+d seconds', $secondsLeft)), $at));
    }

    #[Test]
    public function withNoTransactionOpenTheReadRunsInATransactionOfItsOwnAtTheClocksTime(): void
    {
        $recentPings = $this->pingedState()->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));

        self::assertSame(1, $this->transactionRuns);
        self::assertSame([[self::ACME_FOODS, self::LENA, self::TICKET, '2027-03-15 10:00:00', true]], $this->reads);
        self::assertEquals(new \DateTimeImmutable('2027-03-15 13:00:00+00:00'), $recentPings->againAtOf(new UserId(self::OMAR)));
    }

    #[Test]
    public function insideAnOpenTransactionTheReadRunsThereWithoutATransactionOfItsOwn(): void
    {
        $this->isTransactionOpen = true;

        $this->pingedState()->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));

        self::assertSame(0, $this->transactionRuns);
        self::assertSame([[self::ACME_FOODS, self::LENA, self::TICKET, '2027-03-15 10:00:00', true]], $this->reads);
    }

    #[Test]
    public function theSameOrganizationSenderAndTicketAreReadOncePerRequest(): void
    {
        $pingedState = $this->pingedState();

        $first = $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));
        $second = $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));

        self::assertSame($first, $second);
        self::assertCount(1, $this->reads);
    }

    #[Test]
    public function anotherOrganizationSenderOrTicketIsReadOnItsOwn(): void
    {
        $pingedState = $this->pingedState();

        $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));
        $pingedState->recentPingsOf(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), new TicketId(self::TICKET));
        $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), new TicketId(self::TICKET));
        $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::OTHER_TICKET));

        self::assertSame([
            [self::ACME_FOODS, self::LENA, self::TICKET],
            [self::BETA_BAKERY, self::LENA, self::TICKET],
            [self::ACME_FOODS, self::OMAR, self::TICKET],
            [self::ACME_FOODS, self::LENA, self::OTHER_TICKET],
        ], array_map(static fn (array $read): array => \array_slice($read, 0, 3), $this->reads));
    }

    #[Test]
    public function afterAResetThePingsAreReadAgainAtTheClocksNewTime(): void
    {
        $clock = new MockClock(self::NOW);
        $pingedState = $this->pingedState($clock);
        $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));
        $clock->modify('+5 minutes');

        $pingedState->reset();
        $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));

        self::assertSame(['2027-03-15 10:00:00', '2027-03-15 10:05:00'], array_column($this->reads, 3));
        self::assertSame(2, $this->transactionRuns);
    }

    private function pingedState(?MockClock $clock = null): PingedState
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->isTransactionOpen);

        return new PingedState($this->recentPingQueries(), $this->transactions(), $connection, $clock ?? new MockClock(self::NOW));
    }

    private function recentPingQueries(): RecentPingQueries
    {
        return new class($this) implements RecentPingQueries {
            public function __construct(private readonly PingedStateTest $test)
            {
            }

            public function recentPingsOf(OrganizationId $organizationId, UserId $fromUserId, TicketId $ticketId, \DateTimeImmutable $at): RecentPings
            {
                return $this->test->answerRead($organizationId, $fromUserId, $ticketId, $at);
            }
        };
    }

    /**
     * Refuses to run inside an open transaction and opens one around the write, as DoctrineTransactions does.
     */
    private function transactions(): Transactions
    {
        return new class($this) implements Transactions {
            public function __construct(private readonly PingedStateTest $test)
            {
            }

            public function run(\Closure $write): mixed
            {
                return $this->test->runInATransactionOfItsOwn($write);
            }
        };
    }

    /**
     * @internal for the Transactions double
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public function runInATransactionOfItsOwn(\Closure $write): mixed
    {
        if ($this->isTransactionOpen) {
            throw new \LogicException('A transaction of its own must not run inside another transaction.');
        }
        ++$this->transactionRuns;
        $this->isTransactionOpen = true;
        try {
            return $write();
        } finally {
            $this->isTransactionOpen = false;
        }
    }

    /**
     * Records the read and answers that the sender may ping Omar again three hours after $at.
     *
     * @internal for the RecentPingQueries double
     */
    public function answerRead(OrganizationId $organizationId, UserId $fromUserId, TicketId $ticketId, \DateTimeImmutable $at): RecentPings
    {
        $this->reads[] = [
            $organizationId->toString(),
            $fromUserId->toString(),
            $ticketId->toString(),
            $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $this->isTransactionOpen,
        ];

        return new RecentPings(4, [self::OMAR => $at->modify('+3 hours')]);
    }
}
