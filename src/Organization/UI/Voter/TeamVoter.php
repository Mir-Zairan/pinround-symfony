<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\TeamPermission;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decides TeamPermission VIEW and LEAD (ADR-0013, personas-and-roles.md) from the request's actor. VIEW: any team role
 * in the team, or Admin or Manager of the organization the team belongs to ("Teams at a glance" for all teams). LEAD:
 * only the team's Lead; an Admin or Manager without that team role is denied. A team of another organization is
 * never granted: the actor's roles and teams are read under the request's tenant.
 *
 * The teams of an organization are read at most once per request and forgotten at the kernel's reset between
 * requests (ADR-0008), so a team created later in the same request is unknown to an Admin or Manager until the next.
 *
 * @extends DenyByDefaultVoter<TeamId>
 */
final class TeamVoter extends DenyByDefaultVoter implements ResetInterface
{
    /** @var array<string, list<TeamId>> by organization id */
    private array $teamsOfThisRequest = [];

    public function __construct(private readonly CurrentActor $currentActor, private readonly TeamQueries $teamQueries)
    {
    }

    public function reset(): void
    {
        $this->teamsOfThisRequest = [];
    }

    protected function attributes(): array
    {
        return [TeamPermission::VIEW, TeamPermission::LEAD];
    }

    protected function subjectClass(): string
    {
        return TeamId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor) {
            return false;
        }
        $roles = $actor->roles();

        return match ($attribute) {
            TeamPermission::VIEW => $roles->isInTeam($subject)
                || (($roles->isAdmin() || $roles->isManager()) && $this->isTeamOf($actor->organizationId(), $subject)),
            TeamPermission::LEAD => $roles->isLeadOf($subject),
            default => false,
        };
    }

    private function isTeamOf(OrganizationId $organizationId, TeamId $teamId): bool
    {
        $teamIds = $this->teamsOfThisRequest[$organizationId->toString()] ??= $this->teamQueries->teamsOf($organizationId);

        return array_any($teamIds, static fn (TeamId $candidate): bool => $candidate->equals($teamId));
    }
}
