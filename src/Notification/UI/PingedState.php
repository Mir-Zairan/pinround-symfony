<?php

declare(strict_types=1);

namespace App\Notification\UI;

use App\Notification\Api\PingsPaused;
use App\Notification\Application\Ping\PingWindow;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whom the current person may not ping again yet about a ticket, read from the ping window once per request, ticket and
 * People rows for every row of the ticket page's People, so the page shows what a POST would refuse, with no SQL; and the
 * organization's ping limit for the sheet's note, read once per request. Kept until the kernel resets it between
 * requests (ADR-0008).
 *
 * The wait is shown relative ("again in 3 h"), as Notification's UI may not read the person's time zone from Identity.
 */
final class PingedState implements ResetInterface
{
    /** @var array<string, array<string, \DateTimeImmutable>> by organization, sender, ticket and sorted recipients */
    private array $heldUntilByKey = [];

    /** @var array<string, 1|4|8|24> by organization */
    private array $pingLimitHoursByOrganization = [];

    public function __construct(
        private readonly PingWindow $pingWindow,
        private readonly PingSettingsQueries $pingSettingsQueries,
        private readonly Transactions $transactions,
        private readonly Connection $connection,
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
     * When the ping window lets $fromUserId ping each of $toUserIds about the ticket again; a recipient with a free
     * slot is left out. While the window cannot be reached nobody is shown pinged, and that answer is kept too, so a
     * page of many rows waits for an unreachable store once; the POST still refuses with PingsPaused.
     *
     * @param list<UserId> $toUserIds
     *
     * @return array<string, \DateTimeImmutable> by recipient user id string
     *
     * @throws \LogicException when a slot holds a value the window never writes
     */
    public function heldUntilOf(OrganizationId $organizationId, UserId $fromUserId, TicketId $ticketId, array $toUserIds): array
    {
        $recipientIds = array_map(static fn (UserId $toUserId): string => $toUserId->toString(), $toUserIds);
        sort($recipientIds);
        $key = $organizationId->toString().'|'.$fromUserId->toString().'|'.$ticketId->toString().'|'.implode(',', $recipientIds);
        if (isset($this->heldUntilByKey[$key])) {
            return $this->heldUntilByKey[$key];
        }

        try {
            $heldUntilById = $this->pingWindow->heldUntilOf($organizationId, $fromUserId, $toUserIds, $ticketId);
        } catch (PingsPaused) {
            $heldUntilById = [];
        }

        return $this->heldUntilByKey[$key] = $heldUntilById;
    }

    /**
     * The organization's ping limit, 1, 4, 8 or 24 hours. The settings are read under row-level security, which with no
     * tenant set shows the 4-hour default, so inside an open transaction (the ticket page's, a move's stream re-read) it
     * reads there, and when none is open the read runs in a transaction of its own.
     *
     * @return 1|4|8|24
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24, or no transaction is open and
     *                         the request has no tenant
     */
    public function pingLimitHoursOf(OrganizationId $organizationId): int
    {
        $read = fn (): int => $this->pingSettingsQueries->pingLimitHoursOf($organizationId);

        return $this->pingLimitHoursByOrganization[$organizationId->toString()] ??= $this->connection->isTransactionActive() ? $read() : $this->transactions->run($read);
    }

    /**
     * Forgets the slots and limits read, after a ping is sent in this request too, so the next read sees it.
     */
    public function reset(): void
    {
        $this->heldUntilByKey = [];
        $this->pingLimitHoursByOrganization = [];
    }
}
