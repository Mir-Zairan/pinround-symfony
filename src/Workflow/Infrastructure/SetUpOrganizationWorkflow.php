<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure;

use App\SharedKernel\Application\OrganizationSetupStep;
use App\SharedKernel\Domain\OrganizationId;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\RuleSets;
use App\Workflow\Domain\Step;
use App\Workflow\Domain\StepOrigin;
use App\Workflow\Domain\Steps;

/**
 * Gives a new organization the eight core steps and the default rule set (workflow-engine.md).
 */
final readonly class SetUpOrganizationWorkflow implements OrganizationSetupStep
{
    public function __construct(private Steps $steps, private RuleSets $ruleSets)
    {
    }

    public function setUp(OrganizationId $organizationId): void
    {
        $this->steps->add(...array_map(fn (CoreStep $coreStep): Step => new Step(
            $this->steps->nextIdentity(),
            $organizationId,
            null,
            StepOrigin::Core,
            $coreStep,
            $coreStep->stepName(),
            $coreStep->group(),
            $coreStep->position(),
        ), CoreStep::cases()));
        $this->ruleSets->saveForOrganization($organizationId, RuleSet::defaults());
    }
}
