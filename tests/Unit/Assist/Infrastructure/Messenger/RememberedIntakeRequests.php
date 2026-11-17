<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Messenger;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Check states in memory, one per organization and check as ValkeyIntakeRequests keys them, a failure replacing
 * whatever was there, its draft included. An armed failure is thrown instead of writing, as the real state throws when
 * Valkey cannot be reached, and leaves the states as they were. Only recordFailure is kept; the rest refuse, as no test
 * here needs them.
 */
final class RememberedIntakeRequests implements IntakeRequests
{
    /** @var array<string, array{string, string, ?string, ?string}> status, requester, sentence and draft title by "organization:check" */
    public array $states = [];

    public ?\RuntimeException $failure = null;

    public function open(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, DraftReport $draft): void
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function markAiMayBeAsked(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?DraftReport
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function recordFailure(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, string $sentence): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $this->states[$organizationId->toString().':'.$checkId->toString()] = ['failed', $requesterId->toString(), $sentence, null];
    }

    public function release(OrganizationId $organizationId, IntakeCheckId $checkId): void
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function stateOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeCheckView
    {
        throw new \LogicException('Not kept by this double.');
    }
}
