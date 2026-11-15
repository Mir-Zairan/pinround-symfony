<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The session handler, read-only for a background request (BackgroundRequests). The handler takes no lock, so a
 * background request beside the person's own POST would write back the copy it read before the POST: a flash or a
 * CSRF token the POST saved would be lost, or a flash already shown would come back. Such a request neither writes
 * the session nor renews its TTL, and answers true, so PHP sees a saved session. It decides from the main request on
 * the stack at the time of the write.
 *
 * Except when it stored a CSRF token: a refreshed part can show a form for the first time, which would post a token the
 * session never kept. That rare write keeps the old race.
 */
final readonly class BackgroundReadOnlySessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $sessionHandler,
        private RequestStack $requestStack,
        private BackgroundRequests $backgroundRequests,
        private RecordingCsrfTokenStorage $csrfTokenStorage,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return $this->sessionHandler->open($path, $name);
    }

    public function close(): bool
    {
        return $this->sessionHandler->close();
    }

    public function read(string $id): string|false
    {
        return $this->sessionHandler->read($id);
    }

    public function write(string $id, string $data): bool
    {
        return $this->isReadOnlyRequest() || $this->sessionHandler->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->sessionHandler->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->sessionHandler->gc($max_lifetime);
    }

    public function validateId(string $id): bool
    {
        return $this->sessionHandler->validateId($id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->isReadOnlyRequest() || $this->sessionHandler->updateTimestamp($id, $data);
    }

    private function isReadOnlyRequest(): bool
    {
        $mainRequest = $this->requestStack->getMainRequest();

        return null !== $mainRequest
            && $this->backgroundRequests->isBackground($mainRequest)
            && !$this->csrfTokenStorage->hasStoredToken();
    }
}
