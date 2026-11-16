<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Valkey session handler, writing over the copy stored at the time of the write rather than the one the request
 * read. The handler takes no lock, so a page's lazy frames, which load at once, each write back the copy they read
 * plus their own new CSRF token, and the last write dropped the other frame's token: its forms were then refused as
 * open too long. Each write now takes over the CSRF tokens the stored copy has and this one lacks, in a Valkey
 * transaction (WATCH) that is made again over the new copy when another write came between.
 *
 * It takes over no token this request removed, none after it cleared them (logging out does) and none when the copies
 * hold different logins, and it does not write back a session this request read that is gone from Valkey by the time
 * of the write, so a session that was logged out meanwhile stays logged out.
 */
final class MergingSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface, ResetInterface
{
    public const int MAX_WRITE_ATTEMPTS = 5;

    private const string CSRF_ATTRIBUTE_PREFIX = SessionTokenStorage::SESSION_NAMESPACE.'/';
    /** The firewall's authenticated token, Symfony's ContextListener key _security_<firewall>. */
    private const string LOGIN_ATTRIBUTE_PREFIX = '_security_';

    /** @var array<string, true> */
    private array $sessionIdsReadWithData = [];

    /**
     * @param \Redis $connection the connection the decorated handler writes through, so its write joins the transaction
     * @param string $keyPrefix  the decorated handler's key prefix
     */
    public function __construct(
        private readonly \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $sessionHandler,
        private readonly \Redis $connection,
        private readonly string $keyPrefix,
        private readonly RecordingCsrfTokenStorage $csrfTokenStorage,
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
        $storedSession = $this->sessionHandler->read($id);
        if (\is_string($storedSession) && '' !== $storedSession) {
            $this->sessionIdsReadWithData[$id] = true;
        }

        return $storedSession;
    }

    /**
     * False when another write came between each of MAX_WRITE_ATTEMPTS tries; that write's copy then stays.
     */
    public function write(string $id, string $data): bool
    {
        $key = $this->keyPrefix.$id;
        for ($attempt = 0; $attempt < self::MAX_WRITE_ATTEMPTS; ++$attempt) {
            $this->connection->watch($key);
            $storedSession = $this->connection->get($key);
            if (!\is_string($storedSession) && isset($this->sessionIdsReadWithData[$id])) {
                $this->connection->unwatch();

                return true;
            }

            $this->connection->multi();
            try {
                $this->sessionHandler->write($id, \is_string($storedSession) ? $this->withTokensOnlyStored($data, $storedSession) : $data);
            } catch (\Throwable $exception) {
                $this->connection->discard();

                throw $exception;
            }
            $commandReplies = $this->connection->exec();
            if (\is_array($commandReplies)) {
                return !\in_array(false, $commandReplies, true);
            }
        }

        return false;
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
        return $this->sessionHandler->updateTimestamp($id, $data);
    }

    public function reset(): void
    {
        $this->sessionIdsReadWithData = [];
    }

    /**
     * The written session with the CSRF tokens added that only the stored copy has, or as it is when either copy cannot
     * be read or the rules above keep them out.
     */
    private function withTokensOnlyStored(string $writtenSession, string $storedSession): string
    {
        if ($this->csrfTokenStorage->hasCleared()) {
            return $writtenSession;
        }
        $written = EncodedSessionData::decode($writtenSession);
        $writtenAttributes = $written?->attributes();
        $storedAttributes = EncodedSessionData::decode($storedSession)?->attributes();
        if (null === $written || null === $writtenAttributes || null === $storedAttributes || $written->hasReferences()) {
            return $writtenSession;
        }
        if (self::loginsOf($writtenAttributes) !== self::loginsOf($storedAttributes)) {
            return $writtenSession;
        }

        $tokensOnlyStored = [];
        foreach ($storedAttributes as $name => $rawValue) {
            $name = (string) $name;
            if (str_starts_with($name, self::CSRF_ATTRIBUTE_PREFIX)
                && !\array_key_exists($name, $writtenAttributes)
                && str_starts_with($rawValue, 's:')
                && !$this->csrfTokenStorage->hasRemovedToken(substr($name, \strlen(self::CSRF_ATTRIBUTE_PREFIX)))) {
                $tokensOnlyStored[$name] = $rawValue;
            }
        }

        return [] === $tokensOnlyStored ? $writtenSession : $written->withAttributesAdded($tokensOnlyStored);
    }

    /**
     * @param array<int|string, string> $attributes
     *
     * @return array<int|string, string>
     */
    private static function loginsOf(array $attributes): array
    {
        $logins = array_filter($attributes, static fn (int|string $name): bool => str_starts_with((string) $name, self::LOGIN_ATTRIBUTE_PREFIX), \ARRAY_FILTER_USE_KEY);
        ksort($logins, \SORT_STRING);

        return $logins;
    }
}
