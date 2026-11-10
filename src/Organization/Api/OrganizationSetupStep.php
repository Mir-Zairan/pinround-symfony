<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Something every new organization needs from another module, such as Workflow's core steps, made in the same
 * transaction as the organization so no organization exists without it (ADR-0036). Organization's CreateOrganization
 * runs every step once the organization row exists, highest tag priority first. The module that owns the rows
 * implements it; the `_instanceof` rule in config/services.yaml tags it, as an Api may not use a vendor attribute.
 */
interface OrganizationSetupStep
{
    /**
     * Runs inside the creating transaction, whose tenant is already $organizationId; it must not begin, commit or roll
     * back a transaction. It runs once, for an organization that has nothing yet, so it need not be idempotent: a second
     * run may fail on a unique index. Any exception it throws reaches the caller, which rolls back, so the organization
     * is not created either.
     */
    public function setUp(OrganizationId $organizationId): void;
}
