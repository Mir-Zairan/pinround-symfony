<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\CurrentTimeZone;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The saved zone of the user in the security token. Holds no state of its own, so it is safe in worker mode (ADR-0008).
 */
final readonly class SymfonyCurrentTimeZone implements CurrentTimeZone
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function zone(): \DateTimeZone
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $savedZone = $user instanceof SecurityUser ? $user->timeZone() : null;
        if (null === $savedZone || !\in_array($savedZone, \DateTimeZone::listIdentifiers(), true)) {
            return new \DateTimeZone('UTC');
        }

        return new \DateTimeZone($savedZone);
    }
}
