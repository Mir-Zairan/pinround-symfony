<?php

declare(strict_types=1);

namespace App\Organization\UI\Voter;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Application\OpenInvitationLink;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides OrganizationPermission::ACCEPT_INVITATION (ADR-0013, personas-and-roles.md › Special visibility rules): the
 * account with the invited email, logged in, or nobody logged in when no account has that email yet, so the accept
 * page can create one. Anyone else is denied, also a logged-in person whose account has another email.
 *
 * @extends DenyByDefaultVoter<OpenInvitationLink>
 */
final class InvitationVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentUser $currentUser)
    {
    }

    protected function attributes(): array
    {
        return [OrganizationPermission::ACCEPT_INVITATION];
    }

    protected function subjectClass(): string
    {
        return OpenInvitationLink::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $tokenUser = $token->getUser();
        if (null === $tokenUser) {
            return null === $subject->existingAccountId;
        }
        // A token user Identity does not know is not taken for nobody logged in.
        $loggedInUserId = $this->currentUser->idOf($tokenUser);

        return null !== $loggedInUserId && null !== $subject->existingAccountId && $loggedInUserId->equals($subject->existingAccountId);
    }
}
