<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Identity\Api\LoginAssurance;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Takes a UsernamePasswordToken, as the login form makes, for a password session and any other token kind, such as
 * remember-me, for none; the two-step login is confirmed or not for every token, as set up.
 */
final readonly class FixedLoginAssurance implements LoginAssurance
{
    public function __construct(private bool $hasConfirmedTwoStepLogin)
    {
    }

    public function isPasswordSession(mixed $token): bool
    {
        return $token instanceof UsernamePasswordToken;
    }

    public function hasConfirmedTwoStepLogin(mixed $token): bool
    {
        return $this->hasConfirmedTwoStepLogin;
    }
}
