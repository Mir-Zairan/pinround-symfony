<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\Workflow\Api\StepOccupancy;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\Steps;

/**
 * Removes a company or team step from new moves (workflow-engine.md, "Company steps, team steps and the step
 * policy"): the step is archived, not deleted, as tickets may still name it. Its writes share the caller's
 * transaction, begun under $organizationId. Who may remove it is the voter's question, asked by the UI, as for AddStep.
 */
final readonly class RemoveStep
{
    public function __construct(private Steps $steps, private StepOccupancy $stepOccupancy)
    {
    }

    /**
     * Removing a step that was removed before changes nothing.
     *
     * @throws UnknownStep          when the organization has no such step; nothing is saved
     * @throws CoreStepNotRemovable when it is a core step; nothing is saved
     * @throws StepHasOpenTickets   when open tickets are still in the step; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, StepId $stepId, \DateTimeImmutable $at): void
    {
        $step = $this->steps->byId($stepId);
        if (null === $step || !$step->organizationId()->equals($organizationId)) {
            throw new UnknownStep('That step is not in this organization.');
        }
        if (!$step->isRemovable()) {
            throw new CoreStepNotRemovable(\sprintf('%s is a core step, which every team keeps.', $step->name()));
        }
        $openTickets = $this->stepOccupancy->openTicketsIn($stepId->toString());
        if ($openTickets > 0) {
            throw new StepHasOpenTickets($openTickets);
        }

        $step->archive($at);
        $this->steps->save($step);
    }
}
