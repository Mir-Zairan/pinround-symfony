<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Settings whose step policy is the same for every organization.
 */
final readonly class FixedStepPolicy implements SettingsQueries
{
    public function __construct(private StepPolicy $stepPolicy)
    {
    }

    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
    {
        return $this->stepPolicy;
    }
}
