<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * An organization's invitations, for pages. The read runs under row-level security, so it needs an open transaction
 * whose tenant is the organization; under any other tenant, or none, it finds nothing.
 */
interface InvitationQueries
{
    /** The most invitations one call of pendingOf returns. */
    public const int MAX_LIMIT = 100;

    /**
     * One query, one keyset page of the invitations not yet accepted that have not expired by now, newest first: by
     * invitedAt, then by id, both descending.
     *
     * @param int                    $limit how many to return at most, 1 to MAX_LIMIT
     * @param PendingInvitation|null $after the last invitation of the previous page, of which only id and invitedAt are
     *                                      read; null for the first page
     *
     * @return list<PendingInvitation>
     *
     * @throws \InvalidArgumentException when $limit is below 1 or above MAX_LIMIT
     */
    public function pendingOf(OrganizationId $organizationId, int $limit, ?PendingInvitation $after = null): array;
}
