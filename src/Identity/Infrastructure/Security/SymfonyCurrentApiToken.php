<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The request's token access from its security token: only TokenAuthenticator's ApiTokenAuthentication carries one.
 * Holds no state of its own, so it is safe in worker mode (ADR-0008).
 */
final readonly class SymfonyCurrentApiToken implements CurrentApiToken
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public function access(): ?ApiTokenAccess
    {
        $token = $this->tokenStorage->getToken();

        return $token instanceof ApiTokenAuthentication ? $token->access() : null;
    }
}
