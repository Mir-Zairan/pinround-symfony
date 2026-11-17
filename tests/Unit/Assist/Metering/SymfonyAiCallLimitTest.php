<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Metering;

use App\Assist\Application\AiRateLimited;
use App\Assist\Infrastructure\RateLimit\SymfonyAiCallLimit;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

/**
 * SymfonyAiCallLimit with the limiter ai_calls_per_user as rate_limiter.yaml defines it (pinned in LoginConfigTest), on
 * an in-memory counter instead of the state Valkey. Every call of a test falls in the window its first call opens, so
 * no clock is read.
 */
final class SymfonyAiCallLimitTest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000a501';
    private const string OMAR = '019a5f00-0000-7000-8000-00000000a521';
    private const string MIA = '019a5f00-0000-7000-8000-00000000a522';
    private const int LIMIT = 30;

    private InMemoryStorage $storage;
    private SymfonyAiCallLimit $limit;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $options = self::configuredLimiter();
        unset($options['storage_service'], $options['lock_factory']);
        $this->limit = new SymfonyAiCallLimit(
            new RateLimiterFactory(['id' => 'ai_calls_per_user', ...$options], $this->storage, new LockFactory(new InMemoryStore())),
        );
    }

    #[Test]
    public function thirtyCallsPassAndTheThirtyFirstIsRefusedWithoutBeingCounted(): void
    {
        for ($call = 1; $call <= self::LIMIT; ++$call) {
            $this->limit->consume(self::caller(self::OMAR));
        }
        self::assertSame(self::LIMIT, $this->hitsOf(self::OMAR));

        foreach ([31, 32] as $call) {
            try {
                $this->limit->consume(self::caller(self::OMAR));
                self::fail(\sprintf('Call %d was accepted.', $call));
            } catch (AiRateLimited $refusal) {
                self::assertSame('Too many AI requests. Try again in a minute.', $refusal->getMessage());
                self::assertNull($refusal->provider);
                self::assertNull($refusal->retryAfterSeconds);
            }
        }

        self::assertSame(self::LIMIT, $this->hitsOf(self::OMAR));
    }

    #[Test]
    public function anotherPersonIsNotCountedWithTheOneWhoReachedTheLimit(): void
    {
        for ($call = 1; $call <= self::LIMIT; ++$call) {
            $this->limit->consume(self::caller(self::OMAR));
        }

        $this->limit->consume(self::caller(self::MIA));

        self::assertSame(1, $this->hitsOf(self::MIA));
        self::assertSame(self::LIMIT, $this->hitsOf(self::OMAR));
    }

    #[Test]
    public function theCounterIsKeyedByTheOrganizationAndTheUserId(): void
    {
        $this->limit->consume(self::caller(self::OMAR));

        self::assertNotNull($this->storage->fetch('ai_calls_per_user-'.self::ACME_FOODS.':'.self::OMAR));
    }

    private function hitsOf(string $userId): int
    {
        $window = $this->storage->fetch('ai_calls_per_user-'.self::ACME_FOODS.':'.$userId);
        self::assertInstanceOf(Window::class, $window);

        return $window->getHitCount();
    }

    private static function caller(string $userId): ActorContext
    {
        return new ActorContext(new UserId($userId), new OrganizationId(self::ACME_FOODS), Roles::none());
    }

    /**
     * @return array<string, mixed>
     */
    private static function configuredLimiter(): array
    {
        $section = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/rate_limiter.yaml');
        foreach (['framework', 'rate_limiter', 'ai_calls_per_user'] as $key) {
            self::assertIsArray($section);
            self::assertArrayHasKey($key, $section);
            $section = $section[$key];
        }
        self::assertIsArray($section);

        $limiter = [];
        foreach ($section as $option => $value) {
            $limiter[(string) $option] = $value;
        }

        return $limiter;
    }
}
