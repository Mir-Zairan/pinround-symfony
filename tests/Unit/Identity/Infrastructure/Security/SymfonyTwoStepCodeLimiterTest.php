<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Application\TooManyTwoStepCodeTries;
use App\Identity\Infrastructure\Security\SymfonyTwoStepCodeLimiter;
use App\Identity\Infrastructure\Security\ThrottleKeyHasher;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * With the limit of rate_limiter.yaml (5 per 15 minutes per user) on an in-memory counter.
 */
final class SymfonyTwoStepCodeLimiterTest extends TestCase
{
    private const string OMAR = '019a5f00-0000-7000-8000-000000000001';
    private const string MIA = '019a5f00-0000-7000-8000-000000000002';

    private InMemoryStorage $storage;
    private SymfonyTwoStepCodeLimiter $limiter;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->limiter = new SymfonyTwoStepCodeLimiter(
            new RateLimiterFactory(['id' => 'two_step_code_per_user', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], $this->storage, new LockFactory(new InMemoryStore())),
            15,
            new ThrottleKeyHasher('the-app-secret'),
        );
    }

    #[Test]
    public function theSixthTryOfOneUserWaitsFifteenMinutesAndAnotherUserIsNotCounted(): void
    {
        for ($try = 1; $try <= 5; ++$try) {
            $this->limiter->consume(new UserId(self::OMAR));
        }

        self::assertSame(15, $this->minutesToWaitAfter(self::OMAR));
        $this->limiter->consume(new UserId(self::MIA));
    }

    #[Test]
    public function theCounterIsKeyedByAnHmacOfTheUserIdNotTheIdItself(): void
    {
        $this->limiter->consume(new UserId(self::OMAR));

        $expectedKey = 'two_step_code_per_user-'.new ThrottleKeyHasher('the-app-secret')->hexOf('two-step-code-'.self::OMAR);
        self::assertNotNull($this->storage->fetch($expectedKey));
    }

    private function minutesToWaitAfter(string $userId): int
    {
        try {
            $this->limiter->consume(new UserId($userId));
        } catch (TooManyTwoStepCodeTries $refusal) {
            return $refusal->minutesToWait;
        }

        self::fail('The try was accepted.');
    }
}
