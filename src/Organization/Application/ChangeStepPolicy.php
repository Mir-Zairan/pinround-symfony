<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\StepPolicy;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Sets the organization's step policy (workflow-engine.md), leaving its other settings as they are. Only an Admin may
 * run it: the caller asks `ORG_ADMIN` first. Its write shares the caller's transaction, which must have begun with
 * $organizationId as the tenant. Records no event yet (SettingsChanged waits for its Audit entry).
 */
final readonly class ChangeStepPolicy
{
    public function __construct(private OrgSettingsStore $settings)
    {
    }

    /**
     * When another transaction changed the settings since they were loaded, the store's version check fails (Doctrine's
     * OptimisticLockException) and the caller rolls back.
     *
     * @param ?UserId $actor null when the system changes it; kept with $at for SettingsChanged
     *
     * @throws \LogicException when the organization has no settings row
     */
    public function __invoke(OrganizationId $organizationId, StepPolicy $stepPolicy, ?UserId $actor, \DateTimeImmutable $at): void
    {
        $settings = $this->settings->of($organizationId)
            ?? throw new \LogicException('The organization has no settings.');
        $settings->changeStepPolicy($stepPolicy->value);
        $this->settings->save($settings);
    }
}
