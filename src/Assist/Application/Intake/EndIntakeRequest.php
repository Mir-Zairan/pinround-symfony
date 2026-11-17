<?php

declare(strict_types=1);

namespace App\Assist\Application\Intake;

use App\Assist\Api\IntakeCheckId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Ends a queued "Check with AI" once the job that ran it has committed: released when its suggestion was stored,
 * failed with the line to show otherwise. GenerateIntakeSuggestionHandler dispatches it after the current bus, so it
 * is handled at once (it has no route) and only when the job's transaction committed.
 */
final readonly class EndIntakeRequest
{
    private function __construct(
        public OrganizationId $organizationId,
        public IntakeCheckId $checkId,
        public UserId $requesterId,
        public ?string $failedSentence,
    ) {
    }

    public static function stored(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): self
    {
        return new self($organizationId, $checkId, $requesterId, null);
    }

    public static function failed(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, string $sentence): self
    {
        return new self($organizationId, $checkId, $requesterId, $sentence);
    }
}
