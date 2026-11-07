<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Application\TooManyPasswordResetRequests;
use App\Identity\Infrastructure\Security\SymfonyPasswordResetRequestLimiter;
use App\Identity\Infrastructure\Security\ThrottleKeyHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * With the limits of rate_limiter.yaml (5 per 15 minutes per email and IP, 50 per hour per IP) on in-memory counters.
 */
final class SymfonyPasswordResetRequestLimiterTest extends TestCase
{
    private const string IP = '10.0.0.7';

    private SymfonyPasswordResetRequestLimiter $limiter;

    protected function setUp(): void
    {
        $storage = new InMemoryStorage();
        $lockFactory = new LockFactory(new InMemoryStore());
        $this->limiter = new SymfonyPasswordResetRequestLimiter(
            new RateLimiterFactory(['id' => 'password_reset_per_email_and_ip', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], $storage, $lockFactory),
            15,
            new RateLimiterFactory(['id' => 'password_reset_per_ip', 'policy' => 'fixed_window', 'limit' => 50, 'interval' => '60 minutes'], $storage, $lockFactory),
            60,
            new ThrottleKeyHasher('the-app-secret'),
        );
    }

    #[Test]
    public function theSixthRequestForOneEmailInAnyLetterCaseWaitsFifteenMinutes(): void
    {
        foreach (['omar@acme-foods.example', 'OMAR@acme-foods.example', ' Omar@Acme-Foods.example ', 'omar@acme-foods.example', 'omar@ACME-foods.example'] as $email) {
            $this->limiter->consume($email, self::IP);
        }

        self::assertSame(15, $this->minutesToWaitAfter('omar@acme-foods.example', self::IP));
        $this->limiter->consume('omar@acme-foods.example', '10.0.0.8');
    }

    #[Test]
    public function aRequestRefusedForItsEmailStillCountsForTheIp(): void
    {
        for ($request = 1; $request <= 5; ++$request) {
            $this->limiter->consume('omar@acme-foods.example', self::IP);
        }
        for ($refused = 1; $refused <= 44; ++$refused) {
            self::assertSame(15, $this->minutesToWaitAfter('omar@acme-foods.example', self::IP));
        }

        $this->limiter->consume('mia@acme-foods.example', self::IP);

        self::assertSame(60, $this->minutesToWaitAfter('lena@acme-foods.example', self::IP));
    }

    #[Test]
    public function whenBothLimitsRefuseTheLongerWaitIsNamed(): void
    {
        for ($request = 1; $request <= 5; ++$request) {
            $this->limiter->consume('omar@acme-foods.example', self::IP);
        }
        for ($request = 1; $request <= 45; ++$request) {
            $this->limiter->consume('person'.$request.'@acme-foods.example', self::IP);
        }

        self::assertSame(60, $this->minutesToWaitAfter('omar@acme-foods.example', self::IP));
    }

    private function minutesToWaitAfter(string $email, string $clientIp): int
    {
        try {
            $this->limiter->consume($email, $clientIp);
        } catch (TooManyPasswordResetRequests $refusal) {
            return $refusal->minutesToWait;
        }

        self::fail('The request was accepted.');
    }
}
