<?php

declare(strict_types=1);

namespace App\Identity\UI\Voter;

use App\Identity\Api\LoginAssurance;
use App\Identity\Application\AccountPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides AccountPermission::REVOKE_CURRENT_TOKEN (ADR-0013, P10.T8): a request made with a personal access token may
 * revoke that same token, which only takes rights away. A browser session has no current token, so it is denied; it
 * revokes on the API tokens page instead.
 *
 * @extends Voter<string, null>
 */
final class CurrentApiTokenVoter extends Voter
{
    public const string SESSION_REFUSAL_REASON = 'Only a request made with a personal access token can revoke that token here.';

    public function __construct(private readonly LoginAssurance $loginAssurance)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return AccountPermission::REVOKE_CURRENT_TOKEN === $attribute && null === $subject;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$this->loginAssurance->isTokenAuthenticated($token)) {
            $vote?->addReason(self::SESSION_REFUSAL_REASON);

            return false;
        }

        return true;
    }
}
