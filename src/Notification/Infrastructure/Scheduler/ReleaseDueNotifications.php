<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

/**
 * Releases one organization's notifications held for quiet hours until now or earlier (ReleaseDueNotificationsHandler).
 * Sent to async_notify with a TenantStamp of $organizationId, which the handler refuses to differ from.
 */
final readonly class ReleaseDueNotifications
{
    public function __construct(public string $organizationId)
    {
    }
}
