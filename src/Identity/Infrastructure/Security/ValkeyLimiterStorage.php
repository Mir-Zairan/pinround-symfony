<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\CalendarAlignedWindow;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\TokenBucket;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Keeps the counters of the login, password reset, password change (password_change_per_user) and two-step login code
 * (two_step_code_per_user) limiters on the state Valkey, which never evicts (ADR-0010), and fails closed: a read or
 * write that does not reach Valkey throws, so the login, reset, password change or code request fails instead of
 * running unthrottled. Not a cache pool on purpose: a cache adapter turns a failed read into a miss, and a peek (which
 * never writes) would then let every try
 * through with a fresh window.
 */
final readonly class ValkeyLimiterStorage implements StorageInterface
{
    private const string KEY_PREFIX = 'login_throttling:';

    /** The states of the limiter policies Symfony ships; nothing else is ever unserialized. */
    private const array STATE_CLASSES = [Window::class, CalendarAlignedWindow::class, SlidingWindow::class, TokenBucket::class, Rate::class];

    public function __construct(#[Autowire(service: 'state_valkey.connection')] private \Redis $connection)
    {
    }

    /**
     * Lets the Valkey client's exception through when Valkey cannot be reached.
     *
     * @throws LoginCounterUnusable when Valkey refused the write
     */
    public function save(LimiterStateInterface $limiterState): void
    {
        $expiresAfter = $limiterState->getExpirationTime();
        $options = null === $expiresAfter ? [] : ['EX' => max(1, $expiresAfter)];
        if (true !== $this->connection->set(self::key($limiterState->getId()), serialize($limiterState), $options)) {
            throw new LoginCounterUnusable('The login limiter could not save its counter.');
        }
    }

    /**
     * Lets the Valkey client's exception through when Valkey cannot be reached.
     *
     * @throws LoginCounterUnusable when the stored value is not a limiter state
     */
    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $stored = $this->connection->get(self::key($limiterStateId));
        if (!\is_string($stored)) {
            return null;
        }

        $state = unserialize($stored, ['allowed_classes' => self::STATE_CLASSES]);
        if (!$state instanceof LimiterStateInterface) {
            throw new LoginCounterUnusable('The login limiter found a counter it cannot read.');
        }

        return $state;
    }

    /**
     * Lets the Valkey client's exception through when Valkey cannot be reached.
     */
    public function delete(string $limiterStateId): void
    {
        $this->connection->del(self::key($limiterStateId));
    }

    /**
     * Limiter ids hold the HMAC'd email and IP; hashing keeps every key one short fixed shape.
     */
    private static function key(string $limiterStateId): string
    {
        return self::KEY_PREFIX.sha1($limiterStateId);
    }
}
