<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\Event\StepRemoved;
use App\Workflow\Api\StepOccupancy;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\Steps;

/**
 * Removes a company step (no team) or a team step (workflow-engine.md, "Company steps, team steps and the step
 * policy"): the step is archived, not deleted, as tickets may still name it. Its writes share the caller's
 * transaction, begun under $organizationId. Who may remove it is the voter's question, asked by the UI, as for
 * AddStep: Organization's ORG_ADMIN for a company step, WorkflowPermission::STEP_CHANGE_TEAM on $teamId for a team
 * step. The step must then be exactly of what was voted on, so a lead never removes another team's step or a company
 * step.
 */
final readonly class RemoveStep
{
    public function __construct(private Steps $steps, private StepOccupancy $stepOccupancy, private WorkflowEvents $events)
    {
    }

    /**
     * Removing a step that was removed before changes nothing and publishes nothing; otherwise StepRemoved is
     * published in the caller's transaction.
     *
     * @param ?TeamId $teamId the team whose step it is; null for a company step
     *
     * @throws UnknownStep          when the organization has no step with that id (or the id is not a step id), or the
     *                              step is not $teamId's (not a company step when $teamId is null); nothing is saved
     * @throws CoreStepNotRemovable when it is a core step; nothing is saved
     * @throws StepHasOpenTickets   when open tickets are still in the step; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, ?TeamId $teamId, string $stepId, \DateTimeImmutable $at): void
    {
        try {
            $step = $this->steps->byId(new StepId($stepId));
        } catch (\InvalidArgumentException) {
            $step = null;
        }
        if (null === $step || !$step->organizationId()->equals($organizationId)) {
            throw new UnknownStep('That step is not in this organization.');
        }
        if (!$step->isRemovable()) {
            throw new CoreStepNotRemovable(\sprintf('%s is a core step, which every team keeps.', $step->name()));
        }
        $stepTeamId = $step->teamId();
        if (null === $teamId && null !== $stepTeamId) {
            throw new UnknownStep('That step is not a company step.');
        }
        if (null !== $teamId && (null === $stepTeamId || !$stepTeamId->equals($teamId))) {
            throw new UnknownStep('That step is not one of this team\'s steps.');
        }
        $openTickets = $this->stepOccupancy->openTicketsIn($step->id()->toString());
        if ($openTickets > 0) {
            throw new StepHasOpenTickets($openTickets);
        }

        $wasRemovedBefore = null !== $step->archivedAt();
        $step->archive($at);
        $this->steps->save($step);
        if (!$wasRemovedBefore) {
            $this->events->publish(new StepRemoved($organizationId->toString(), $step->id()->toString(), $teamId?->toString(), $at));
        }
    }
}
