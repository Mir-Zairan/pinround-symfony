<?php

declare(strict_types=1);

namespace App\Notification\Application\Realtime;

/**
 * Tells one person's open pages that their bell changed: worker-notify publishes the new count, a fresh popover frame
 * and the toast, when given, on that person's own topic (RefreshBellHandler). Sent to async_notify under the
 * organization of $organizationId, which the handler refuses to differ from.
 */
final readonly class RefreshBell
{
    /**
     * @param string|null $toast plain text the person's pages show as a toast, such as "Lena answered your question.";
     *                           never ticket text (ADR-0027)
     */
    public function __construct(
        public string $organizationId,
        public string $userId,
        public ?string $toast,
    ) {
    }
}
