<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Organization\Api\StepPolicy;

/**
 * Who may change one team's own steps and rules under the organization's step policy (workflow-engine.md, "Company
 * steps, team steps and the step policy"; personas-and-roles.md, "Add / remove team steps" and "Change team rules").
 * Only that team's lead, never an org role: an Admin who also leads the team has the rights as its lead. A policy
 * this class does not name grants nothing.
 *
 * It lives in Application, not Domain, because UI\Voter\WorkflowVoter asks it and Workflow's UI may not see its Domain.
 */
final readonly class StepChangeRights
{
    public function __construct(private StepPolicy $stepPolicy, private bool $isLeadOfTeam)
    {
    }

    public function mayChangeTeamSteps(): bool
    {
        return $this->isLeadOfTeam && \in_array($this->stepPolicy, [StepPolicy::Extend, StepPolicy::Team], true);
    }

    public function mayChangeTeamRules(): bool
    {
        return $this->isLeadOfTeam && StepPolicy::Team === $this->stepPolicy;
    }
}
