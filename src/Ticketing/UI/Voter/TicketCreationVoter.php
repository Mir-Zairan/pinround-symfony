<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decides TicketPermission CREATE for the team a new ticket is for: every user of the team's organization, with any
 * org or team role or none ("Report a problem (create ticket)" in ADR-0013 and personas-and-roles.md). A team of
 * another organization is denied. It is TicketVoter's attribute on another subject, a TeamId, which DenyByDefaultVoter
 * lets a voter decide only one class of.
 *
 * The teams of an organization are read at most once per request and forgotten at the kernel's reset between
 * requests (ADR-0008), as ProjectVoter does.
 *
 * @extends DenyByDefaultVoter<TeamId>
 */
final class TicketCreationVoter extends DenyByDefaultVoter implements ResetInterface
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
        return [TicketPermission::CREATE];
    }

    protected function subjectClass(): string
    {
        return TeamId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());

        return null !== $actor && $this->isTeamOf($actor->organizationId(), $subject);
    }

    private function isTeamOf(OrganizationId $organizationId, TeamId $teamId): bool
    {
        $teamIds = $this->teamsOfThisRequest[$organizationId->toString()] ??= $this->teamQueries->teamsOf($organizationId);

        return array_any($teamIds, static fn (TeamId $candidate): bool => $candidate->equals($teamId));
    }
}
