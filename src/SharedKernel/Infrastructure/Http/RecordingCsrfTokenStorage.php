<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The CSRF token storage, noting whether the current request stored a token, so BackgroundReadOnlySessionHandler can
 * save the session of a background request whose page rendered a form for the first time. The token manager stores a
 * token only when the session has none for its id, so a stored token is always a new or renewed one.
 */
#[AsDecorator('security.csrf.token_storage')]
final class RecordingCsrfTokenStorage implements ClearableTokenStorageInterface, ResetInterface
{
    private bool $hasStoredToken = false;

    public function __construct(#[AutowireDecorated] private readonly ClearableTokenStorageInterface $tokenStorage)
    {
    }

    public function getToken(string $tokenId): string
    {
        return $this->tokenStorage->getToken($tokenId);
    }

    public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
    {
        $this->tokenStorage->setToken($tokenId, $token);
        $this->hasStoredToken = true;
    }

    public function removeToken(string $tokenId): ?string
    {
        return $this->tokenStorage->removeToken($tokenId);
    }

    public function hasToken(string $tokenId): bool
    {
        return $this->tokenStorage->hasToken($tokenId);
    }

    public function clear(): void
    {
        $this->tokenStorage->clear();
    }

    /**
     * Whether a token was stored since the last reset, which the worker does between requests.
     */
    public function hasStoredToken(): bool
    {
        return $this->hasStoredToken;
    }

    public function reset(): void
    {
        $this->hasStoredToken = false;
    }
}
