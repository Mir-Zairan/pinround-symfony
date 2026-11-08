<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\LoginAssurance;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Reads the assurance from the token's class. The form login and SymfonyCurrentSession::keepAfterPasswordChange create
 * exactly a UsernamePasswordToken; the class is compared exactly, so its subclass SwitchUserToken (impersonation) and
 * every other token kind, remember-me and the personal access token login of P09 included, are refused.
 */
final readonly class SymfonyLoginAssurance implements LoginAssurance
{
    public function isPasswordSession(mixed $token): bool
    {
        return $token instanceof UsernamePasswordToken
            && UsernamePasswordToken::class === $token::class
            && $token->getUser() instanceof SecurityUser;
    }

    /**
     * TODO(P01.T6): true once the person confirmed a TOTP code in this session; no one can until two-step login exists.
     */
    public function hasConfirmedTwoStepLogin(mixed $token): bool
    {
        return false;
    }
}
