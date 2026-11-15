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
     * done, created at or after $since and held until the same time as $reply (both not held included), or, for a $reply
     * that is not held, one whose hold ended at or before $reply's created_at though not released yet: its actor, data
     * and created_at become $reply's, its deliver_after stays. A held reply never folds into a shown one, which would
     * show it before its time. It first takes a transaction lock on (organization, recipient, message), held until the
     * caller's transaction ends, so two replies handled at once cannot both find nothing and both add a row.
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

    /**
     * Takes a transaction lock on (organization, message), held until the caller's transaction ends. A writer that
     * checks the message before adding a notification about it takes it first, so markDoneAboutMessage, which takes it
     * too, either runs after that row is committed and marks it, or before the check, which then sees the message closed.
     *
     * @throws \LogicException when no transaction is open, before any lock
     */
    public function lockAboutMessage(OrganizationId $organizationId, string $messageId): void;

    /**
     * Takes the lockAboutMessage lock, then marks done, at $at, the notifications of the given kinds about the message
     * (data.messageId) on the ticket that are not done yet, held ones included, and drops each recipient's bell count
     * once the transaction commits.
     *
     * @param non-empty-list<NotificationKind> $kinds
     *
     * @return list<UserId> each recipient of a marked notification once, in user id order; none when none was open
     *
     * @throws \LogicException when no transaction is open, before any lock or write
     */
    public function markDoneAboutMessage(OrganizationId $organizationId, TicketId $ticketId, string $messageId, array $kinds, \DateTimeImmutable $at): array;
}
