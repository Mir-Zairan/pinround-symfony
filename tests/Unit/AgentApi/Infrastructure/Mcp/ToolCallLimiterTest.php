<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\AgentApi\Infrastructure\Mcp\ToolCallsLimited;
use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * ToolCallLimiter with its limiters as rate_limiter.yaml defines them, on an in-memory counter instead of the state
 * Valkey, and a clock frozen 20 seconds into the first minute of an hour in 2099, so a wait counted from the window's
 * start (60, 3600) or from the real time differs from the right one (40, 3580 less the minutes moved).
 */
final class ToolCallLimiterTest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000c601';
    private const string BETA_BAKERY = '019a5f00-0000-7000-8000-00000000c602';
    private const string OMARS_TOKEN = '019a5f00-0000-7000-8000-00000000c611';
    private const string MIAS_TOKEN = '019a5f00-0000-7000-8000-00000000c612';
    private const string NOW = '2099-03-20 09:00:20 UTC';
    /** The minute of NOW since the epoch: (4077680420 - 20) / 60. */
    private const int MINUTE_OF_NOW = 67961340;
    /** The hour of NOW since the epoch: (4077680420 - 20) / 3600. */
    private const int HOUR_OF_NOW = 1132689;
    private const int CALLS_PER_TOKEN_MINUTE = 60;
    private const int CALLS_PER_TOKEN_HOUR = 600;
    private const int CALLS_PER_ORGANIZATION_MINUTE = 600;

    private InMemoryStorage $storage;
    private MockClock $clock;
    private ToolCallLimiter $limiter;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $this->limiter = ConfiguredToolCallLimiter::on($this->storage, $this->clock);
    }

    #[Test]
    public function sixtyCallsOfATokenPassAndTheSixtyFirstIsRefusedUntilTheClocksNextMinute(): void
    {
        $this->makeCalls(self::OMARS_TOKEN, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);

        $refusal = $this->refusalOf(self::OMARS_TOKEN, self::ACME_FOODS);

        self::assertSame(ToolCallLimiter::TOKEN_TOO_FAST, $refusal->getMessage());
        self::assertSame(40, $refusal->retryAfterSeconds);
    }

    #[Test]
    public function aCallRefusedForTheTokensMinuteSpendsNothingOfItsHourOrOfItsOrganizationsMinute(): void
    {
        $this->makeCalls(self::OMARS_TOKEN, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);

        foreach ([61, 62] as $call) {
            self::assertSame(ToolCallLimiter::TOKEN_TOO_FAST, $this->refusalOf(self::OMARS_TOKEN, self::ACME_FOODS)->getMessage(), \sprintf('Call %d', $call));
        }

        self::assertSame(60, $this->hitsOf('mcp_calls_per_token_minute', self::OMARS_TOKEN.':'.self::MINUTE_OF_NOW));
        self::assertSame(60, $this->hitsOf('mcp_calls_per_token_hour', self::OMARS_TOKEN.':'.self::HOUR_OF_NOW));
        self::assertSame(60, $this->hitsOf('mcp_calls_per_organization_minute', self::ACME_FOODS.':'.self::MINUTE_OF_NOW));
    }

    #[Test]
    public function aTokenAtItsMinuteLimitMayCallAgainWhenTheClocksNextMinuteBegins(): void
    {
        $this->makeCalls(self::OMARS_TOKEN, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);
        $this->clock->modify('+39 seconds');
        self::assertSame(1, $this->refusalOf(self::OMARS_TOKEN, self::ACME_FOODS)->retryAfterSeconds);

        $this->clock->modify('+1 second');
        $this->limiter->consume(self::access(self::OMARS_TOKEN, self::ACME_FOODS));

        self::assertSame(1, $this->hitsOf('mcp_calls_per_token_minute', self::OMARS_TOKEN.':'.(self::MINUTE_OF_NOW + 1)));
        self::assertSame(61, $this->hitsOf('mcp_calls_per_token_hour', self::OMARS_TOKEN.':'.self::HOUR_OF_NOW));
    }

    #[Test]
    public function sixHundredCallsOfATokenInAnHourPassAndTheNextIsRefusedUntilTheClocksNextHour(): void
    {
        for ($minute = 1; $minute <= self::CALLS_PER_TOKEN_HOUR / self::CALLS_PER_TOKEN_MINUTE; ++$minute) {
            $this->makeCalls(self::OMARS_TOKEN, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);
            $this->clock->modify('+1 minute');
        }

        $refusal = $this->refusalOf(self::OMARS_TOKEN, self::ACME_FOODS);

        self::assertSame(ToolCallLimiter::TOKEN_HOUR_SPENT, $refusal->getMessage());
        self::assertSame(3600 - 10 * 60 - 20, $refusal->retryAfterSeconds);
        self::assertSame(600, $this->hitsOf('mcp_calls_per_token_hour', self::OMARS_TOKEN.':'.self::HOUR_OF_NOW));
        self::assertSame(1, $this->hitsOf('mcp_calls_per_token_minute', self::OMARS_TOKEN.':'.(self::MINUTE_OF_NOW + 10)), 'The token minute comes before the hour, so it is spent.');
        self::assertNull($this->storage->fetch('mcp_calls_per_organization_minute-'.self::ACME_FOODS.':'.(self::MINUTE_OF_NOW + 10)), 'The organization minute comes after the hour, so nothing of it is spent.');
    }

    #[Test]
    public function sixHundredCallsOfAnOrganizationInAMinutePassAcrossItsTokensAndTheNextIsRefused(): void
    {
        $tokenIds = self::tokenIds(self::CALLS_PER_ORGANIZATION_MINUTE / self::CALLS_PER_TOKEN_MINUTE + 1);
        $lastTokenId = array_pop($tokenIds);
        self::assertNotNull($lastTokenId);
        self::assertCount(10, $tokenIds);
        foreach ($tokenIds as $tokenId) {
            $this->makeCalls($tokenId, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);
        }

        $refusal = $this->refusalOf($lastTokenId, self::ACME_FOODS);

        self::assertSame(ToolCallLimiter::ORGANIZATION_TOO_FAST, $refusal->getMessage());
        self::assertSame(40, $refusal->retryAfterSeconds);
        self::assertSame(600, $this->hitsOf('mcp_calls_per_organization_minute', self::ACME_FOODS.':'.self::MINUTE_OF_NOW));
        self::assertSame(1, $this->hitsOf('mcp_calls_per_token_minute', $lastTokenId.':'.self::MINUTE_OF_NOW));
    }

    #[Test]
    public function anotherTokenIsNotCountedWithTheOneAtItsLimit(): void
    {
        $this->makeCalls(self::OMARS_TOKEN, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);

        $this->limiter->consume(self::access(self::MIAS_TOKEN, self::ACME_FOODS));

        self::assertSame(1, $this->hitsOf('mcp_calls_per_token_minute', self::MIAS_TOKEN.':'.self::MINUTE_OF_NOW));
        self::assertSame(1, $this->hitsOf('mcp_calls_per_token_hour', self::MIAS_TOKEN.':'.self::HOUR_OF_NOW));
        self::assertSame(60, $this->hitsOf('mcp_calls_per_token_minute', self::OMARS_TOKEN.':'.self::MINUTE_OF_NOW));
        self::assertSame(61, $this->hitsOf('mcp_calls_per_organization_minute', self::ACME_FOODS.':'.self::MINUTE_OF_NOW));
    }

    #[Test]
    public function anotherOrganizationIsNotCountedWithTheOneAtItsLimit(): void
    {
        foreach (self::tokenIds(self::CALLS_PER_ORGANIZATION_MINUTE / self::CALLS_PER_TOKEN_MINUTE) as $tokenId) {
            $this->makeCalls($tokenId, self::ACME_FOODS, self::CALLS_PER_TOKEN_MINUTE);
        }

        $this->limiter->consume(self::access(self::MIAS_TOKEN, self::BETA_BAKERY));

        self::assertSame(1, $this->hitsOf('mcp_calls_per_organization_minute', self::BETA_BAKERY.':'.self::MINUTE_OF_NOW));
        self::assertSame(600, $this->hitsOf('mcp_calls_per_organization_minute', self::ACME_FOODS.':'.self::MINUTE_OF_NOW));
    }

    private function makeCalls(string $tokenId, string $organizationId, int $calls): void
    {
        for ($call = 1; $call <= $calls; ++$call) {
            $this->limiter->consume(self::access($tokenId, $organizationId));
        }
    }

    private function refusalOf(string $tokenId, string $organizationId): ToolCallsLimited
    {
        try {
            $this->limiter->consume(self::access($tokenId, $organizationId));
        } catch (ToolCallsLimited $refusal) {
            return $refusal;
        }
        self::fail('The call was accepted.');
    }

    private function hitsOf(string $limiterId, string $key): int
    {
        $window = $this->storage->fetch($limiterId.'-'.$key);
        self::assertInstanceOf(Window::class, $window, \sprintf('No counter for %s-%s.', $limiterId, $key));

        return $window->getHitCount();
    }

    /**
     * @return list<string> distinct token ids, none of them the named tokens'
     */
    private static function tokenIds(int $count): array
    {
        $tokenIds = [];
        for ($index = 1; $index <= $count; ++$index) {
            $tokenIds[] = \sprintf('019a5f00-0000-7000-8000-%012d', 900 + $index);
        }

        return $tokenIds;
    }

    private static function access(string $tokenId, string $organizationId): ApiTokenAccess
    {
        return new ApiTokenAccess($tokenId, new OrganizationId($organizationId), ['read'], new \DateTimeImmutable('2099-06-18 09:00:20 UTC'));
    }
}
