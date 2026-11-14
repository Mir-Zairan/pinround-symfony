<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Writes ntf_notification rows under row-level security, in the caller's transaction under the notification's
 * organization.
 */
interface Notifications
{
    /**
     * @throws \LogicException when no transaction is open, before any write
     */
    public function add(NewNotification $notification): void;

    /**
     * Folds $reply into the recipient's reply notification about the same message (data.messageId) that is unread, not
     * done, created at or after $since and held until the same time as $reply (both not held included): its actor, data
     * and created_at become $reply's. A held reply never folds into a shown one, which would show it before its time. It first takes a
     * transaction lock on (organization, recipient, message), held until the caller's transaction ends, so two
     * replies handled at once cannot both find nothing and both add a row.
     *
     * @return bool whether such a notification was found and changed; false changes nothing
     *
     * @throws \LogicException when no transaction is open, or $reply is not a reply or has no messageId, before any
     *                         write
     */
    public function foldIntoUnreadReply(NewNotification $reply, \DateTimeImmutable $since): bool;

    /**
     * Releases the organization's notifications held until $at or earlier: their deliver_after becomes null, and each
     * recipient's bell count drops once the transaction commits. One statement both releases and finds the
     * recipients, so of two releases at once only the first finds a row.
     *
     * @return list<UserId> each recipient of a released notification once, in user id order; none when nothing was due
     *
     * @throws \LogicException when no transaction is open, before any write
     */
    public function releaseDue(OrganizationId $organizationId, \DateTimeImmutable $at): array;

    /**
     * Marks done, at $at, the recipient's ping notifications about the ticket that are not done yet, held ones
     * included, and drops their bell count once the transaction commits.
     *
     * @return int how many it marked
     *
     * @throws \LogicException when no transaction is open, before any write
     */
    public function markPingsDone(OrganizationId $organizationId, UserId $recipientId, TicketId $ticketId, \DateTimeImmutable $at): int;
}
