<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\AccountPermission;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides AccountPermission::EDIT_OWN (ADR-0013): only a person logged in with their password in this session may
 * change their own account. Here, not in Identity UI, because it must see SecurityUser, which the UI layer cannot
 * (Deptrac).
 *
 * TODO(P09): refuse the token a personal access token login creates once that login exists; an API token must never
 * change the password or end the sessions of its user (FR-ID-05).
 *
 * @extends Voter<string, null>
 */
final class OwnAccountVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return AccountPermission::EDIT_OWN === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // A remember-me login has not seen the password in this session (there is none today; this keeps it out).
        return $token->getUser() instanceof SecurityUser && !$token instanceof RememberMeToken;
    }
}
