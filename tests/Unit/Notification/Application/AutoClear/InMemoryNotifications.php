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
 * Like DbalNotifications for the rows, their done_at and their deliver_after, and for the recipients a release or a
 * mark done about a message returns (each once, in user id order); folding replies is not modelled and refused, and a
 * lock about a message is only recorded, as one process holds no lock against itself.
 */
final class InMemoryNotifications implements Notifications
{
    /** @var list<array{string, string}> organization and message id of each lock about a message, in the order taken */
    public array $lockedMessages = [];

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

    public function lockAboutMessage(OrganizationId $organizationId, string $messageId): void
    {
        $this->lockedMessages[] = [$organizationId->toString(), $messageId];
    }

    public function markDoneAboutMessage(OrganizationId $organizationId, TicketId $ticketId, string $messageId, array $kinds, \DateTimeImmutable $at): array
    {
        $this->lockAboutMessage($organizationId, $messageId);
        $recipientIds = [];
        foreach ($this->rows as $index => $row) {
            $notification = $row['notification'];
            if (null === $row['doneAt']
                && \in_array($notification->kind, $kinds, true)
                && ($notification->data['messageId'] ?? null) === $messageId
                && $notification->organizationId->equals($organizationId)
                && $notification->ticketId->equals($ticketId)
            ) {
                $this->rows[$index]['doneAt'] = $at;
                $recipientIds[$notification->recipientId->toString()] = $notification->recipientId;
            }
        }
        ksort($recipientIds, \SORT_STRING);

        return array_values($recipientIds);
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
