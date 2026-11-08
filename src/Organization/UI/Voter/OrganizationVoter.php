<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides OrganizationPermission VIEW and ADMIN (ADR-0013) from the request's actor: VIEW for the organization the
 * request works in, ADMIN when the actor is also an Admin there. Being in that organization is the proof of
 * membership: EnterChosenOrganization sets the request's tenant only to an organization of the logged-in user
 * (MembershipQueries::organizationsOf), and CurrentActor gives no actor without a tenant.
 *
 * @extends DenyByDefaultVoter<OrganizationId>
 */
final class OrganizationVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentActor $currentActor)
    {
    }

    protected function attributes(): array
    {
        return [OrganizationPermission::VIEW, OrganizationPermission::ADMIN];
    }

    protected function subjectClass(): string
    {
        return OrganizationId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor || !$actor->organizationId()->equals($subject)) {
            return false;
        }

        return match ($attribute) {
            OrganizationPermission::VIEW => true,
            OrganizationPermission::ADMIN => $actor->roles()->isAdmin(),
            default => false,
        };
    }
}
