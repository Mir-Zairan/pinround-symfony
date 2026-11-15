<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

/**
 * The kinds of ntf_notification.kind that NotifyRecipients writes, as realtime-and-notifications.md › Notification
 * kinds names them.
 */
enum NotificationKind: string
{
    case Ping = 'ping';
    case Question = 'question';
    case Answer = 'answer';
    case Reply = 'reply';
    case Assigned = 'assigned';
}
