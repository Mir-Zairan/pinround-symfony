<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The logged-in user from the security token. Holds no state of its own, so it is safe in worker mode (ADR-0008).
 */
final readonly class SymfonyCurrentUser implements CurrentUser
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function id(): ?UserId
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof SecurityUser ? new UserId($user->id()) : null;
    }
}
