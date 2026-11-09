<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Identity\Api\LoginAssurance;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Takes a UsernamePasswordToken, as the login form makes, for a password session and any other token kind, such as
 * remember-me, for none. Two-step login is on or off and the last code was typed $codeProofAgeSeconds ago (null:
 * never), as set up, for every token: so only the voter's own password-session check refuses other token kinds.
 */
final readonly class FixedLoginAssurance implements LoginAssurance
{
    public function __construct(private bool $isTwoStepLoginOn, private ?int $codeProofAgeSeconds)
    {
    }

    public function isPasswordSession(mixed $token): bool
    {
        return $token instanceof UsernamePasswordToken;
    }

    public function isTwoStepLoginOn(mixed $token): bool
    {
        return $this->isTwoStepLoginOn;
    }

    public function hasConfirmedTwoStepLoginWithin(mixed $token, int $seconds): bool
    {
        return $this->isTwoStepLoginOn && null !== $this->codeProofAgeSeconds && $this->codeProofAgeSeconds <= $seconds;
    }
}
