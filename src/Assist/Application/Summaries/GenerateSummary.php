<?php

declare(strict_types=1);

namespace App\Assist\Application\Summaries;

/**
 * Writes an AI summary of one ticket a person asked for with "Summarize with AI" (GenerateSummaryHandler, in
 * worker-heavy). Sent with a TenantStamp of $organizationId, which the handler refuses to differ from.
 */
final readonly class GenerateSummary
{
    public function __construct(
        public string $organizationId,
        public string $ticketId,
        public string $requesterId,
    ) {
    }
}
