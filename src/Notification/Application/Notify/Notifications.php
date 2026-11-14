<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

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
     * done and created at or after $since: its actor, data and created_at become $reply's. It first takes a
     * transaction lock on (organization, recipient, message), held until the caller's transaction ends, so two
     * replies handled at once cannot both find nothing and both add a row.
     *
     * @return bool whether such a notification was found and changed; false changes nothing
     *
     * @throws \LogicException when no transaction is open, or $reply is not a reply or has no messageId, before any
     *                         write
     */
    public function foldIntoUnreadReply(NewNotification $reply, \DateTimeImmutable $since): bool;
}
