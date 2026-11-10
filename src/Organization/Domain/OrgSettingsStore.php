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

    /**
     * Writes the changes to settings loaded with of() at once. The update checks the version they were loaded with, so
     * it fails when another transaction changed them meanwhile; the caller must then roll back.
     *
     * @throws \LogicException when the settings were neither loaded through of() nor added; nothing is written
     */
    public function save(OrgSettings $settings): void;
}
