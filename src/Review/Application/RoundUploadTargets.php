<?php

declare(strict_types=1);

namespace App\Review\Application;

/**
 * Reads a round as an upload target for RoundMediaTarget, under row-level security, so it needs an open transaction
 * whose tenant is the round's organization.
 */
interface RoundUploadTargets
{
    /**
     * @param string $roundId the id as the upload request carried it, in any case
     *
     * @return RoundUploadTarget|null null for a malformed id or a round the tenant has not, without a query for the
     *                                malformed one
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function of(string $roundId): ?RoundUploadTarget;
}
