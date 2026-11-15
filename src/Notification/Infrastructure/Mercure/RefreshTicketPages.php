<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

/**
 * Tells the pages open on a ticket to fetch it again, through a content-free signal on the ticket's topic
 * (RefreshTicketPagesHandler). It carries ids only, never ticket text (ADR-0027), and goes to async_notify.
 */
final readonly class RefreshTicketPages
{
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public RefreshAttempt $attempt,
    ) {
    }
}
