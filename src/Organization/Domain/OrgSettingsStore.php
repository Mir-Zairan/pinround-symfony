<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * The settings row of the organization of the current transaction.
 */
interface OrgSettingsStore
{
    public function of(OrganizationId $organizationId): ?OrgSettings;

    /**
     * Saves the settings at once; a second row for the same organization fails here.
     */
    public function add(OrgSettings $settings): void;
}
