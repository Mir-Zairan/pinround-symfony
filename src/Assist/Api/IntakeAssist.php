<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * "Check with AI" for a draft report: queue a check for a person and let that person, and only them, read how it is
 * doing and what it suggests. The AI runs in worker-heavy (async_ai); no web request waits on it. A done check is kept
 * in ast_suggestion; it suggests only, and nothing is written to a ticket until the person clicks (ADR-0020).
 */
interface IntakeAssist
{
    /**
     * Checks the draft's sizes, then decides the organization's AI policy for the person and draft as the job's gateway
     * call will, the draft's words included, and whether the person has an AI call left without counting one, then
     * keeps the draft with the check's state and queues the job. Reads under row-level security, so it needs an open
     * transaction whose tenant is $organizationId. Queueing does not wait for that transaction: the job is sent at once.
     * Whether the person may report in the project is the page's voter's to decide (TicketCreationVoter).
     *
     * @return IntakeCheckId the id to poll with checkOf()
     *
     * @throws IntakeCheckRefused when the project id is no canonical uuid, the draft is too long or not UTF-8, the
     *                            person is no member, the policy says no (AI off or for leads only, the laptop, or a
     *                            draft that mentions security on its way to cloud AI) or the person has no AI call left
     *                            this hour; nothing is kept or queued then
     * @throws \RuntimeException  when the check's state or the queue cannot be reached; the check is not kept then, so
     *                            the person may try again
     * @throws \Throwable         the call limit's storage error when it cannot be read; nothing is queued then
     */
    public function suggest(DraftReport $draft, UserId $requesterId, OrganizationId $organizationId): IntakeCheckId;

    /**
     * The check for the person who asked for it. Reads the check's state, then its stored suggestion and the viewer's
     * membership under row-level security, so it needs an open transaction whose tenant is $organizationId.
     *
     * @return IntakeCheckView|null null when the check is unknown, its state expired, it is another person's (an
     *                              admin's included) or another organization's, or the viewer is no longer a member;
     *                              the same null for each, so it never tells whether a check exists
     *
     * @throws \RuntimeException when the check's state cannot be reached or holds a value it did not write, or its stored
     *                           suggestion is unreadable
     */
    public function checkOf(IntakeCheckId $checkId, UserId $viewerId, OrganizationId $organizationId): ?IntakeCheckView;
}
