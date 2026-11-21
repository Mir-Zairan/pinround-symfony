<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Application\TooManyOAuthClientRegistrations;
use App\Identity\Infrastructure\Security\SymfonyOAuthClientRegistrationLimiter;
use App\Identity\Infrastructure\Security\ThrottleKeyHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * With the limit of rate_limiter.yaml (30 per 60 minutes per IP) on in-memory counters.
 */
final class SymfonyOAuthClientRegistrationLimiterTest extends TestCase
{
    private const string IP = '2001:db8::7';

    private SymfonyOAuthClientRegistrationLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new SymfonyOAuthClientRegistrationLimiter(
            new RateLimiterFactory(
                ['id' => 'oauth_client_registration_per_ip', 'policy' => 'fixed_window', 'limit' => 30, 'interval' => '60 minutes'],
                new InMemoryStorage(),
                new LockFactory(new InMemoryStore()),
            ),
            60,
            new ThrottleKeyHasher('the-app-secret'),
        );
    }

    #[Test]
    public function theThirtyFirstRequestFromOneAddressWaitsSixtyMinutes(): void
    {
        for ($request = 1; $request <= 30; ++$request) {
            $this->limiter->consume(self::IP);
        }

        self::assertSame(60, $this->minutesToWaitAfter(self::IP));
        self::assertSame(60, $this->minutesToWaitAfter(self::IP));
    }

    #[Test]
    public function anotherAddressKeepsItsOwnCount(): void
    {
        for ($request = 1; $request <= 30; ++$request) {
            $this->limiter->consume(self::IP);
        }

        $this->limiter->consume('2001:db8::8');

        self::assertSame(60, $this->minutesToWaitAfter(self::IP));
    }

    private function minutesToWaitAfter(string $clientIp): int
    {
        try {
            $this->limiter->consume($clientIp);
        } catch (TooManyOAuthClientRegistrations $refusal) {
            return $refusal->minutesToWait;
        }

        self::fail('The request was accepted.');
    }
}
