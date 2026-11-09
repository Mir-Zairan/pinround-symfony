<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something every new organization needs from another module, such as Workflow's core steps, made in the same
 * transaction as the organization so no organization exists without it. Organization's CreateOrganization runs every
 * step, highest `#[AsTaggedItem(priority: ...)]` first, once the organization row exists. The module that owns the
 * rows implements it and autoconfiguration tags it; it lives here because Organization may not import that module.
 */
#[AutoconfigureTag('app.organization_setup_step')]
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
