<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One ntf_notification row to write: who gets it, what it is about, and the snapshot the bell shows without reading
 * another module.
 */
final readonly class NewNotification
{
    /**
     * @param array<string, string>   $data         actorName and ticketKey as they were when it was written, and the ids
     *                                              of what it is about (pingId, messageId, replyId)
     * @param \DateTimeImmutable      $createdAt    the time of the event; its UTC month picks the partition
     * @param \DateTimeImmutable|null $deliverAfter when the bell may show it; null for one that is never held
     */
    public function __construct(
        public OrganizationId $organizationId,
        public UserId $recipientId,
        public NotificationKind $kind,
        public UserId $actorId,
        public TicketId $ticketId,
        public array $data,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $deliverAfter = null,
    ) {
    }
}
