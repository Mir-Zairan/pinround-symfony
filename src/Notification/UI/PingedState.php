<?php

declare(strict_types=1);

namespace App\Notification\UI;

use App\Notification\Application\Ping\RecentPingQueries;
use App\Notification\Application\Ping\RecentPings;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whom the current person pinged about a ticket lately, read once per request and ticket for every row of the ticket
 * page's People. Kept until the kernel resets it between requests (ADR-0008).
 *
 * The wait is shown relative ("again in 3 h"), as Notification's UI may not read the person's time zone from Identity.
 */
final class PingedState implements ResetInterface
{
    /** @var array<string, RecentPings> by organization, sender and ticket */
    private array $recentPingsByKey = [];

    public function __construct(
        private readonly RecentPingQueries $recentPingQueries,
        private readonly Transactions $transactions,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * "again in 4 h", the hours rounded up; under an hour, "again in 25 min", the minutes rounded up, at least 1.
     */
    public static function againInOf(\DateTimeImmutable $againAt, \DateTimeImmutable $at): string
    {
        $seconds = max(1, $againAt->getTimestamp() - $at->getTimestamp());
        if ($seconds < 3600) {
            return \sprintf('again in %d min', (int) ceil($seconds / 60));
        }

        return \sprintf('again in %d h', (int) ceil($seconds / 3600));
    }

    /**
     * Runs the read in a transaction of its own when none is open, as the stream answers of a move render the ticket's
     * People after the move's transaction ended; inside an open one it reads there.
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24, or no transaction is open and
     *                         the request has no tenant
     */
    public function recentPingsOf(OrganizationId $organizationId, UserId $fromUserId, TicketId $ticketId): RecentPings
    {
        $key = $organizationId->toString().'|'.$fromUserId->toString().'|'.$ticketId->toString();
        if (isset($this->recentPingsByKey[$key])) {
            return $this->recentPingsByKey[$key];
        }
        $at = $this->clock->now();
        $read = fn (): RecentPings => $this->recentPingQueries->recentPingsOf($organizationId, $fromUserId, $ticketId, $at);

        return $this->recentPingsByKey[$key] = $this->connection->isTransactionActive() ? $read() : $this->transactions->run($read);
    }

    /**
     * Forgets the pings read, after a ping is sent in this request too, so the next read sees it.
     */
    public function reset(): void
    {
        $this->recentPingsByKey = [];
    }
}
