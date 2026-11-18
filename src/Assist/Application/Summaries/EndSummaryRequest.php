<?php

declare(strict_types=1);

namespace App\Assist\Application\Summaries;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Ends a ticket's queued summary request once the job that ran it has committed: released when its summary was
 * stored, failed with the line to show otherwise. GenerateSummaryHandler dispatches it after the current bus, so it is
 * handled at once (it has no route) and only when the job's transaction committed.
 */
final readonly class EndSummaryRequest
{
    private function __construct(
        public OrganizationId $organizationId,
        public TicketId $ticketId,
        public UserId $requesterId,
        public ?string $failedSentence,
    ) {
    }

    public static function stored(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): self
    {
        return new self($organizationId, $ticketId, $requesterId, null);
    }

    public static function failed(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId, string $sentence): self
    {
        return new self($organizationId, $ticketId, $requesterId, $sentence);
    }
}
