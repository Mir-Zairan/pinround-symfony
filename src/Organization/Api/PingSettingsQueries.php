<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * An organization's ping limit, for other modules. The read runs under row-level security, so it needs an open
 * transaction whose tenant is the organization; under any other tenant, or none, it finds no settings row.
 */
interface PingSettingsQueries
{
    /**
     * How many hours a person waits before pinging the same person about the same ticket again (data.ping_limit_hours):
     * 1, 4, 8 or 24. One query, not cached. 4, the default, when the organization has no settings row (or it is not
     * visible under the current tenant) or the row has no ping limit.
     *
     * @return 1|4|8|24
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24
     */
    public function pingLimitHoursOf(OrganizationId $organizationId): int;
}
