<?php

declare(strict_types=1);

namespace App\Workflow\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\SettingsQueries;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Workflow\Api\WorkflowPermission;
use App\Workflow\Application\StepChangeRights;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides WorkflowPermission STEP_CHANGE_TEAM and RULES_CHANGE_TEAM (ADR-0013) from the request's actor and their
 * organization's step policy, through StepChangeRights. Only the team's Lead is ever granted, and the actor's roles
 * are read under the request's tenant, so a team of another organization is never granted without reading the teams.
 *
 * A vote for a lead of the team reads the step policy (one query), anyone else is denied without one; nothing is kept
 * between votes, so a policy changed earlier in the request counts at once.
 *
 * @extends DenyByDefaultVoter<TeamId>
 */
final class WorkflowVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentActor $currentActor, private readonly SettingsQueries $settingsQueries)
    {
    }

    protected function attributes(): array
    {
        return [WorkflowPermission::STEP_CHANGE_TEAM, WorkflowPermission::RULES_CHANGE_TEAM];
    }

    protected function subjectClass(): string
    {
        return TeamId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor || !$actor->roles()->isLeadOf($subject)) {
            return false;
        }
        $rights = new StepChangeRights($this->settingsQueries->stepPolicyOf($actor->organizationId()), isLeadOfTeam: true);

        return match ($attribute) {
            WorkflowPermission::STEP_CHANGE_TEAM => $rights->mayChangeTeamSteps(),
            WorkflowPermission::RULES_CHANGE_TEAM => $rights->mayChangeTeamRules(),
            default => false,
        };
    }
}
