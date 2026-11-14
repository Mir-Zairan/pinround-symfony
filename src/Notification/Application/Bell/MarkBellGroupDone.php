<?php

declare(strict_types=1);

namespace App\Notification\Application\Bell;

use App\Notification\Api\Event\PingCleared;
use App\Notification\Api\Event\PingClearedReason as ClearedReasonOfEvent;
use App\Notification\Application\Ping\PingEvents;
use App\Notification\Domain\Ping;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\Pings;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;

/**
 * Done on one group of the viewer's bell: their open notifications from one sender about one ticket, and the sender's
 * open pings to them on that ticket, which clear as done with a PingCleared(Done) each. A ping still held by quiet hours
 * stays open: the viewer has not seen it. A notification keeps its ticket after the viewer leaves the ticket's team, so
 * the group changes only while Ticketing finds the ticket visible to them (ADR-0013). Runs in the request transaction
 * under the tenant.
 */
final readonly class MarkBellGroupDone
{
    public function __construct(
        private TicketQueries $ticketQueries,
        private BellInbox $bellInbox,
        private Pings $pings,
        private PingEvents $pingEvents,
    ) {
    }

    /**
     * Locks the viewer's open pings on the ticket until the transaction ends whenever the ticket is visible to them, a
     * group with none open included, before it marks any notification.
     *
     * @return int how many notifications it marked done; 0 when the group has none open or the viewer may no longer
     *             see the ticket, and then nothing changed, its pings included
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     */
    public function markDone(TicketVisibility $viewer, UserId $senderId, TicketId $ticketId, \DateTimeImmutable $now): int
    {
        if ([] === $this->ticketQueries->visibleTicketIdsOf($viewer, [$ticketId])) {
            return 0;
        }

        // ClearPingsOnAction locks the pings before it marks their bell rows; the same order here keeps the two from
        // deadlocking.
        $openPings = $this->pings->lockedOpenPingsTo($viewer->viewerId(), $ticketId);
        $markedCount = $this->bellInbox->markGroupDone(new BellGroupKey($viewer->viewerId(), $senderId, $ticketId), $now);
        if (0 === $markedCount) {
            return 0;
        }

        foreach ($openPings as $ping) {
            if ($ping->fromId()->equals($senderId) && self::isDelivered($ping, $now)) {
                $this->clearAsDone($ping, $viewer->viewerId(), $now);
            }
        }

        return $markedCount;
    }

    private static function isDelivered(Ping $ping, \DateTimeImmutable $now): bool
    {
        return null === $ping->deliverAfter() || $ping->deliverAfter() <= $now;
    }

    private function clearAsDone(Ping $ping, UserId $viewerId, \DateTimeImmutable $now): void
    {
        $ping->clear(PingClearedReason::Done, $now);
        $this->pings->save($ping);
        $this->pingEvents->publish(new PingCleared(
            $ping->organizationId()->toString(),
            $ping->ticketId()->toString(),
            $ping->id()->toString(),
            $ping->fromId()->toString(),
            $viewerId->toString(),
            ClearedReasonOfEvent::Done,
            $viewerId->toString(),
            $now,
        ));
    }
}
