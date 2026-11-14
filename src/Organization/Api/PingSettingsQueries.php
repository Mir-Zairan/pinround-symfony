<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * An organization's ping settings, for other modules. The read runs under row-level security, so it needs an open
 * transaction whose tenant is the organization; under any other tenant, or none, it finds no settings row and every
 * method gives its default.
 */
interface PingSettingsQueries
{
    /**
     * How many hours a person waits before pinging the same person about the same ticket again (data.ping_limit_hours):
     * 1, 4, 8 or 24. One query, or none: the autowired one keeps the answer, read with the other settings, until the
     * next request or message; SendPing, which takes the slot in its own transaction, is given the uncached one
     * (services.yaml). 4, the default, when the organization has no settings row (or it is not
     * visible under the current tenant) or the row has no ping limit.
     *
     * @return 1|4|8|24
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24
     */
    public function pingLimitHoursOf(OrganizationId $organizationId): int;

    /**
     * Whether acting on a ticket clears the actor's open pings on it (data.auto_clear). True, the default, when the
     * organization has no settings row (or it is not visible under the current tenant) or the row has no switch.
     * Cached like pingLimitHoursOf.
     *
     * @throws \LogicException when the stored switch is not a JSON boolean
     */
    public function isAutoClearOn(OrganizationId $organizationId): bool;

    /**
     * Whether pings and notifications made between 18:00 and 09:00 in the recipient's time zone wait until 09:00
     * (data.quiet_hours). False, the default, when the organization has no settings row (or it is not visible under
     * the current tenant) or the row has no switch. Cached like pingLimitHoursOf.
     *
     * @throws \LogicException when the stored switch is not a JSON boolean
     */
    public function isQuietHoursOn(OrganizationId $organizationId): bool;
}
