<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Organizations under row-level security: an organization's own row is visible only while it is the tenant.
 */
interface Organizations
{
    /**
     * A new id no organization has yet: a UUIDv7, so ids sort by creation time (ADR-0006).
     */
    public function nextIdentity(): OrganizationId;

    /**
     * @return Organization|null null also when $id is not the organization of the current transaction
     */
    public function byId(OrganizationId $id): ?Organization;

    /**
     * Saves the organization at once, so a slug another organization has already fails here: the unique index
     * refuses it and the save throws. The organization must be the tenant of the current transaction.
     */
    public function add(Organization $organization): void;
}
