<?php

declare(strict_types=1);

namespace App\Identity\UI\Voter;

use App\Identity\Api\LoginAssurance;
use App\Identity\Application\AccountPermission;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decides AccountPermission::EDIT_OWN (ADR-0013): only a person logged in with their password in this session may
 * change their own account. Any logged-in user qualifies: the pages act on the user of the session, never on an id
 * from the request. A personal access token never qualifies: it must not change the password or end the sessions of
 * its user (FR-ID-05).
 *
 * @extends Voter<string, null>
 */
final class OwnAccountVoter extends Voter
{
    public function __construct(private readonly LoginAssurance $loginAssurance)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return AccountPermission::EDIT_OWN === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // A remember-me login has not seen the password in this session (there is none today; this keeps it out).
        return $token->getUser() instanceof UserInterface && !$token instanceof RememberMeToken
            && !$this->loginAssurance->isTokenAuthenticated($token);
    }
}
