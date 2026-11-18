<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\RateLimit;

use App\Assist\Application\LaptopRequestLimit;
use App\Assist\Application\LaptopRequestsLimited;
use App\Assist\Infrastructure\RateLimit\SymfonyLaptopRequestLimit;
use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

/**
 * SymfonyLaptopRequestLimit with the limiter ai_laptop_per_user as rate_limiter.yaml defines it (pinned in
 * LoginConfigTest), on an in-memory counter instead of the state Valkey, and a clock frozen 20 seconds into a minute
 * of 12 March 2027, so a wait counted from the minute's start (60) or the real time differs from the right one (40).
 */
final class SymfonyLaptopRequestLimitTest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000a601';
    private const string BETA_BAKERY = '019a5f00-0000-7000-8000-00000000a602';
    private const string OMAR = '019a5f00-0000-7000-8000-00000000a621';
    private const string MIA = '019a5f00-0000-7000-8000-00000000a622';
    private const string NOW = '2027-03-12 09:15:20 UTC';
    /** The minute of NOW since the epoch: (1804842920 - 20) / 60. */
    private const int MINUTE_OF_NOW = 30080715;

    private InMemoryStorage $storage;
    private MockClock $clock;
    private SymfonyLaptopRequestLimit $limit;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $options = self::configuredLimiter();
        unset($options['storage_service'], $options['lock_factory']);
        $this->limit = new SymfonyLaptopRequestLimit(
            new RateLimiterFactory(['id' => 'ai_laptop_per_user', ...$options], $this->storage, new LockFactory(new InMemoryStore())),
            $this->clock,
        );
    }

    #[Test]
    public function thirtyRequestsPassAndTheThirtyFirstIsRefusedUntilTheNextMinuteWithoutBeingCounted(): void
    {
        $this->sendRequests(self::OMAR, LaptopRequestLimit::REQUESTS_PER_MINUTE);
        self::assertSame(30, $this->hitsOf(self::OMAR));

        foreach ([31, 32] as $request) {
            $refusal = $this->refusalOf(self::OMAR);
            self::assertSame('You sent too many laptop AI requests. Wait a minute and try again.', $refusal->getMessage(), \sprintf('Request %d', $request));
            self::assertSame(40, $refusal->retryAfterSeconds);
        }

        self::assertSame(30, $this->hitsOf(self::OMAR));
    }

    #[Test]
    public function theWaitIsCountedToTheNextMinuteOfTheClock(): void
    {
        $this->clock->modify('+39 seconds');
        $this->sendRequests(self::OMAR, LaptopRequestLimit::REQUESTS_PER_MINUTE);

        self::assertSame(1, $this->refusalOf(self::OMAR)->retryAfterSeconds);
    }

    #[Test]
    public function aPersonAtTheLimitMaySendAgainWhenTheClocksNextMinuteBegins(): void
    {
        $this->sendRequests(self::OMAR, LaptopRequestLimit::REQUESTS_PER_MINUTE);
        $this->clock->modify('+39 seconds');
        self::assertSame(1, $this->refusalOf(self::OMAR)->retryAfterSeconds);

        $this->clock->modify('+1 second');
        $this->limit->consume(self::caller(self::OMAR));

        self::assertSame(1, $this->hitsOf(self::OMAR, self::MINUTE_OF_NOW + 1));
        self::assertSame(30, $this->hitsOf(self::OMAR));
    }

    #[Test]
    public function anotherPersonAndTheSamePersonInAnotherOrganizationAreNotCountedWithTheOneAtTheLimit(): void
    {
        $this->sendRequests(self::OMAR, LaptopRequestLimit::REQUESTS_PER_MINUTE);

        $this->limit->consume(self::caller(self::MIA));
        $this->limit->consume(new ActorContext(new UserId(self::OMAR), new OrganizationId(self::BETA_BAKERY), Roles::none()));

        self::assertSame(1, $this->hitsOf(self::MIA));
        $window = $this->storage->fetch('ai_laptop_per_user-'.self::BETA_BAKERY.':'.self::OMAR.':'.self::MINUTE_OF_NOW);
        self::assertInstanceOf(Window::class, $window);
        self::assertSame(1, $window->getHitCount());
        self::assertSame(30, $this->hitsOf(self::OMAR));
    }

    private function sendRequests(string $userId, int $requests): void
    {
        for ($request = 1; $request <= $requests; ++$request) {
            $this->limit->consume(self::caller($userId));
        }
    }

    private function refusalOf(string $userId): LaptopRequestsLimited
    {
        try {
            $this->limit->consume(self::caller($userId));
        } catch (LaptopRequestsLimited $refusal) {
            return $refusal;
        }
        self::fail('The request was accepted.');
    }

    private function hitsOf(string $userId, int $minute = self::MINUTE_OF_NOW): int
    {
        $window = $this->storage->fetch('ai_laptop_per_user-'.self::ACME_FOODS.':'.$userId.':'.$minute);
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
        $section = Yaml::parseFile(\dirname(__DIR__, 5).'/config/packages/rate_limiter.yaml');
        foreach (['framework', 'rate_limiter', 'ai_laptop_per_user'] as $key) {
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
