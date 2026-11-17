<?php

declare(strict_types=1);

namespace App\Assist\Application\Intake;

/**
 * Checks one draft report a person sent with "Check with AI" (GenerateIntakeSuggestionHandler, in worker-heavy). It
 * carries ids only: the draft waits in the check's state (IntakeRequests), so a job Messenger gives up on keeps no
 * report text in the failed transport. Sent with a TenantStamp of $organizationId, which the handler refuses to differ
 * from.
 */
final readonly class GenerateIntakeSuggestion
{
    public function __construct(
        public string $organizationId,
        public string $checkId,
        public string $requesterId,
    ) {
    }
}
