<?php

declare(strict_types=1);

namespace App\Review\Application\ReportDraft;

use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftCursor;
use App\Review\Api\ReportDraftsPage;
use App\Review\Api\ReportDraftView;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The rvw_report_draft rows of the tenant's organization. Every read and write names the owner, so another person's
 * draft is never found. Each method runs in the caller's open transaction under the organization as tenant.
 *
 * @throws \LogicException from every method when no transaction is open or no tenant is set, before any query
 */
interface ReportDraftRows
{
    public function nextId(): string;

    /**
     * @param ReportDraftContent $content checked by ReportDraftContentRules
     */
    public function insert(OrganizationId $organizationId, string $id, UserId $owner, string $projectKey, ReportDraftContent $content, \DateTimeImmutable $at): void;

    /**
     * At most $pageSize of the owner's drafts, newest change first (updated_at, then id, highest first), starting
     * right after $after; the next cursor is set only when another draft follows.
     */
    public function forOwner(UserId $owner, ?ReportDraftCursor $after, int $pageSize): ReportDraftsPage;

    /**
     * @param bool $lock true to hold the row until the caller's transaction ends (SELECT … FOR UPDATE), so a second
     *                   Send or edit at once waits and then finds it changed or gone
     */
    public function ofOwner(string $id, UserId $owner, bool $lock = false): ?ReportDraftView;

    /**
     * Writes the content and the next version when the row is $owner's and still at $expectedVersion.
     *
     * @return bool false when no row matched, so nothing was written
     */
    public function update(string $id, UserId $owner, int $expectedVersion, ReportDraftContent $content, \DateTimeImmutable $at): bool;

    /**
     * @param int|null $expectedVersion null to remove whatever version it is at
     *
     * @return bool false when no row matched, so nothing was removed
     */
    public function delete(string $id, UserId $owner, ?int $expectedVersion = null): bool;
}
