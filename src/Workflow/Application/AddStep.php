<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Domain\BlankStepName;
use App\Workflow\Domain\DoneGroupStepRefused;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\Step;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\StepOrigin;
use App\Workflow\Domain\Steps;

/**
 * Adds a company step (no team) or a team step (FR-WF-03, FR-WF-04). Its writes share the caller's transaction, begun
 * under $organizationId. Who may add it is the voter's question, asked by the UI: Organization's ORG_ADMIN for a
 * company step, WorkflowPermission::STEP_CHANGE_TEAM for a team step.
 */
final readonly class AddStep
{
    public function __construct(private Steps $steps, private SettingsQueries $settingsQueries, private TeamQueries $teamQueries)
    {
    }

    /**
     * The new step comes last among the steps of its group it is listed with (core and company steps, plus the team's
     * own for a team step). Two steps added at the same moment may share a position; lists then order them by id.
     *
     * @param string $groupCode one of todo, doing, check, wait (done is refused)
     *
     * @throws StepGroupUnknown  when the group code is not one of the five; nothing is saved
     * @throws StepTeamNotFound  when the team is not a team of the organization; nothing is saved
     * @throws TeamStepsLocked   when a team step is added under the step policy locked; nothing is saved
     * @throws StepNameMissing   when nothing is left of the name after trimming; nothing is saved
     * @throws StepInDoneRefused when the group is done; nothing is saved
     */
    public function __invoke(OrganizationId $organizationId, ?TeamId $teamId, string $name, string $groupCode): StepId
    {
        $group = Group::tryFrom($groupCode) ?? throw new StepGroupUnknown(\sprintf('There is no group "%s".', $groupCode));
        if (null !== $teamId) {
            $isTeamOfTheOrganization = array_any(
                $this->teamQueries->teamsOf($organizationId),
                static fn (TeamId $organizationTeam): bool => $organizationTeam->equals($teamId),
            );
            if (!$isTeamOfTheOrganization) {
                throw new StepTeamNotFound('That team is not in this organization.');
            }
            if (StepPolicy::Locked === $this->settingsQueries->stepPolicyOf($organizationId)) {
                throw new TeamStepsLocked('Your admin has locked the steps, so teams cannot add their own.');
            }
        }

        try {
            $step = new Step(
                $this->steps->nextIdentity(),
                $organizationId,
                $teamId,
                null === $teamId ? StepOrigin::Company : StepOrigin::Team,
                null,
                $name,
                $group,
                ($this->steps->highestPositionIn($group, $teamId) ?? -1) + 1,
            );
        } catch (BlankStepName $blankName) {
            throw new StepNameMissing($blankName->getMessage(), 0, $blankName);
        } catch (DoneGroupStepRefused $inDone) {
            throw new StepInDoneRefused($inDone->getMessage(), 0, $inDone);
        }
        $this->steps->add($step);

        return $step->id();
    }
}
