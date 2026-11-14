<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Every setting that other modules read through SettingsQueries, in one query, for the reader that keeps them for a
 * request.
 */
interface StoredSettingsQueries
{
    /**
     * Extend, no triager and a ping limit of 4 hours when the organization has no settings row (or it is not visible under the current tenant);
     * each missing key reads as it does through SettingsQueries.
     *
     * @throws \LogicException           when the stored step policy is not one of StepPolicy's values, or the stored ping
     *                                   limit not one of 1, 4, 8 or 24
     * @throws \InvalidArgumentException when the stored triager is not a user id
     */
    public function settingsOf(OrganizationId $organizationId): StoredSettings;
}
