<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\OrganizationPermission;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides OrganizationPermission CHOOSE and ENTER (ADR-0013): any logged-in user may open the picker of their own
 * organizations (CHOOSE), and only a member may enter an organization (ENTER). It decides for the user of the token it
 * is given (CurrentUser::idOf), not for the request's. Membership comes from the resolver,
 * never from the request, so an id sent in a form proves nothing by itself.
 *
 * @extends Voter<string, OrganizationId|null>
 */
final class OrganizationMemberVoter extends Voter
{
    public function __construct(private readonly CurrentUser $currentUser, private readonly MembershipQueries $membershipQueries)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (OrganizationPermission::CHOOSE === $attribute && null === $subject)
            || (OrganizationPermission::ENTER === $attribute && $subject instanceof OrganizationId);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $userId = $this->currentUser->idOf($token->getUser());
        if (null === $userId) {
            return false;
        }
        if (null === $subject) {
            return true;
        }

        return array_any(
            $this->membershipQueries->organizationsOf($userId),
            static fn (OrganizationId $organizationId): bool => $organizationId->equals($subject),
        );
    }
}
