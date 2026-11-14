<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\AutoClear;

use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Application\Notify\Notifications;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Like DbalNotifications for the rows, their done_at and their deliver_after, and for the recipients a release
 * returns (each once, in user id order); folding replies is not modelled and refused.
 */
final class InMemoryNotifications implements Notifications
{
    /** @var list<array{notification: NewNotification, doneAt: ?\DateTimeImmutable, deliverAfter: ?\DateTimeImmutable}> in the order added */
    private array $rows = [];

    public function add(NewNotification $notification): void
    {
        $this->rows[] = ['notification' => $notification, 'doneAt' => null, 'deliverAfter' => $notification->deliverAfter];
    }

    public function foldIntoUnreadReply(NewNotification $reply, \DateTimeImmutable $since): bool
    {
        throw new \LogicException('InMemoryNotifications does not fold replies.');
    }

    public function markPingsDone(OrganizationId $organizationId, UserId $recipientId, TicketId $ticketId, \DateTimeImmutable $at): int
    {
        $marked = 0;
        foreach ($this->rows as $index => $row) {
            $notification = $row['notification'];
            if (null === $row['doneAt']
                && NotificationKind::Ping === $notification->kind
                && $notification->organizationId->equals($organizationId)
                && $notification->recipientId->equals($recipientId)
                && $notification->ticketId->equals($ticketId)
            ) {
                $this->rows[$index]['doneAt'] = $at;
                ++$marked;
            }
        }

        return $marked;
    }

    public function releaseDue(OrganizationId $organizationId, \DateTimeImmutable $at): array
    {
        $recipientIds = [];
        foreach ($this->rows as $index => $row) {
            $notification = $row['notification'];
            if (null !== $row['deliverAfter'] && $row['deliverAfter'] <= $at && $notification->organizationId->equals($organizationId)) {
                $this->rows[$index]['deliverAfter'] = null;
                $recipientIds[$notification->recipientId->toString()] = $notification->recipientId;
            }
        }
        ksort($recipientIds, \SORT_STRING);

        return array_values($recipientIds);
    }

    /**
     * @return list<?\DateTimeImmutable> each row's done_at, in the order added
     */
    public function doneTimes(): array
    {
        return array_column($this->rows, 'doneAt');
    }

    /**
     * @return list<?\DateTimeImmutable> each row's deliver_after, in the order added
     */
    public function deliveryTimes(): array
    {
        return array_column($this->rows, 'deliverAfter');
    }
}
