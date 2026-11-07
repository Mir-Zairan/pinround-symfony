<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\ConsumeFirstLoginRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * With the limits of rate_limiter.yaml on in-memory counters. The minutes are computed the way LoginThrottlingListener
 * computes the "Wait n minutes" of the login page.
 */
final class ConsumeFirstLoginRateLimiterTest extends TestCase
{
    private const string IP = '10.0.0.7';

    private ConsumeFirstLoginRateLimiter $limiter;

    protected function setUp(): void
    {
        $storage = new InMemoryStorage();
        $this->limiter = new ConsumeFirstLoginRateLimiter(
            new RateLimiterFactory(['id' => 'login_per_email_and_ip', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], $storage),
            15,
            new RateLimiterFactory(['id' => 'login_per_ip', 'policy' => 'fixed_window', 'limit' => 50, 'interval' => '60 minutes'], $storage),
            60,
            'the-app-secret',
        );
    }

    #[Test]
    public function itCannotOnlyPeekSoTheFirewallChargesATryBeforeThePasswordCheck(): void
    {
        self::assertNotContains(PeekableRequestRateLimiterInterface::class, class_implements(ConsumeFirstLoginRateLimiter::class));
    }

    #[Test]
    public function theSixthTryForOneEmailAndIpIsRefusedForAtMostFifteenMinutes(): void
    {
        foreach (['omar@acme-foods.example', 'OMAR@acme-foods.example', 'Omar@Acme-Foods.example', 'omar@acme-foods.example', 'omar@ACME-foods.example'] as $email) {
            self::assertTrue($this->limiter->consume(self::loginRequest($email))->isAccepted());
        }

        $sixth = $this->limiter->consume(self::loginRequest('omar@acme-foods.example'));

        self::assertFalse($sixth->isAccepted());
        self::assertSame(15, self::minutesToWait($sixth));
        self::assertTrue($this->limiter->consume(self::loginRequest('mia@acme-foods.example'))->isAccepted());
    }

    #[Test]
    public function theFiftyFirstTryFromOneIpIsRefusedForAtMostSixtyMinutesWhateverTheEmail(): void
    {
        for ($try = 1; $try <= 50; ++$try) {
            self::assertTrue($this->limiter->consume(self::loginRequest('person'.$try.'@acme-foods.example'))->isAccepted());
        }

        $refused = $this->limiter->consume(self::loginRequest('fresh@acme-foods.example'));

        self::assertFalse($refused->isAccepted());
        self::assertSame(60, self::minutesToWait($refused));
        self::assertTrue($this->limiter->consume(self::loginRequest('fresh@acme-foods.example', '10.0.0.8'))->isAccepted());
    }

    #[Test]
    public function aSuccessfulLoginGivesBackTheEmailAndIpTriesButNotTheIpOnes(): void
    {
        for ($try = 1; $try <= 5; ++$try) {
            $this->limiter->consume(self::loginRequest('omar@acme-foods.example'));
        }
        for ($try = 1; $try <= 44; ++$try) {
            $this->limiter->consume(self::loginRequest('person'.$try.'@acme-foods.example'));
        }

        $this->limiter->reset(self::loginRequest('omar@acme-foods.example'));

        self::assertTrue($this->limiter->consume(self::loginRequest('omar@acme-foods.example'))->isAccepted());
        self::assertFalse($this->limiter->consume(self::loginRequest('omar@acme-foods.example'))->isAccepted(), 'the 51st try from the IP');
    }

    private static function minutesToWait(RateLimit $limit): int
    {
        return (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60);
    }

    private static function loginRequest(string $email, string $ip = self::IP): Request
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return $request;
    }
}
