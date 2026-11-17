<?php

declare(strict_types=1);

namespace App\Assist\Application\Intake;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The state of each "Check with AI" until its suggestion is stored: queued with its draft while its job waits or runs,
 * failed with its line after the job gave up, and none once the suggestion is stored. Each check has its own state and
 * belongs to the person who asked. A queued state ends on its own after an hour and a failed one after a day, so a
 * lost job leaves no draft behind for long.
 */
interface IntakeRequests
{
    /**
     * Keeps the check queued for the requester with their draft, as of now; the check and the write are one step.
     *
     * @throws \RuntimeException when the state cannot be reached or the check already has one
     */
    public function open(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, DraftReport $draft): void;

    /**
     * Marks the requester's queued check as one that may have asked the AI, before its job asks, and hands over its
     * draft, which the state forgets; the check and the write are one step, so of two deliveries of one job only one
     * may ask and pay. The mark does not prove a provider call happened. It outlives a rolled-back transaction and
     * keeps the state's expiry.
     *
     * @return DraftReport|null null when the check was marked already, or is no queued check of this requester; nothing
     *                          changed then
     *
     * @throws \RuntimeException when the state cannot be reached or holds a value this class did not write
     */
    public function markAiMayBeAsked(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?DraftReport;

    /**
     * Replaces the check's state with failed, as of now, with the line to show; its draft is gone then.
     *
     * @throws \RuntimeException when the state cannot be reached
     */
    public function recordFailure(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, string $sentence): void;

    /**
     * Forgets the check's state: its suggestion was stored, or its job could not be queued.
     *
     * @throws \RuntimeException when the state cannot be reached
     */
    public function release(OrganizationId $organizationId, IntakeCheckId $checkId): void;

    /**
     * @return IntakeCheckView|null queued or failed; null when the check has no state or another person asked for it
     *
     * @throws \RuntimeException when the state cannot be reached or holds a value this class did not write
     */
    public function stateOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeCheckView;
}
