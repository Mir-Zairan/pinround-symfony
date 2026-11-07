<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses a blocked user only after their password was checked, so the refusal takes as long as a wrong password and
 * shows the same "Email or password is wrong". An open session of a blocked user ends in SecurityUserProvider.
 */
final readonly class BlockedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof SecurityUser && $user->isBlocked()) {
            throw new DisabledException();
        }
    }
}
