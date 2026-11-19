<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\RateLimit;

use App\Media\Api\UploadRefused;
use App\Media\Infrastructure\RateLimit\SymfonyUploadBeginLimit;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * SymfonyUploadBeginLimit with media_upload_begin_per_user as rate_limiter.yaml defines it (pinned in LoginConfigTest),
 * on an in-memory counter instead of the state Valkey. The fixed window counts on the wall clock (microtime), which no
 * MockClock reaches, so the wait is checked against its bounds rather than an exact value.
 */
final class SymfonyUploadBeginLimitTest extends TestCase
{
    private const string OMAR = '019a5f00-0000-7000-8000-00000000d701';
    private const string LENA = '019a5f00-0000-7000-8000-00000000d702';
    private const int BEGINS_PER_MINUTE = 60;

    private InMemoryStorage $storage;
    private SymfonyUploadBeginLimit $uploadBeginLimit;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->uploadBeginLimit = new SymfonyUploadBeginLimit(
            new RateLimiterFactory(['id' => 'media_upload_begin_per_user', 'policy' => 'fixed_window', 'limit' => self::BEGINS_PER_MINUTE, 'interval' => '1 minute'], $this->storage),
            1,
        );
    }

    #[Test]
    public function sixtyBeginsOfAPersonPassAndTheSixtyFirstIsRefusedWithAWaitWithinTheMinute(): void
    {
        $this->makeBegins(self::OMAR, self::BEGINS_PER_MINUTE);

        $refusal = $this->refusalOf(self::OMAR);

        self::assertSame(UploadRefused::ADDING_TOO_FAST, $refusal->getMessage());
        self::assertNotNull($refusal->retryAfterSeconds);
        self::assertGreaterThanOrEqual(1, $refusal->retryAfterSeconds);
        self::assertLessThanOrEqual(60, $refusal->retryAfterSeconds);
    }

    /**
     * The web and MCP count a begin before they ask Uploads::begin, so one Uploads refuses afterwards is spent too:
     * the count grows with each accepted consume, whatever the caller does next.
     */
    #[Test]
    public function everyAcceptedBeginIsCountedAtOnceSoTheNextOverTheLimitIsRefusedWhateverCameAfterIt(): void
    {
        $this->makeBegins(self::OMAR, 1);
        self::assertSame(1, $this->beginsCountedFor(self::OMAR));

        $this->makeBegins(self::OMAR, self::BEGINS_PER_MINUTE - 1);
        self::assertSame(self::BEGINS_PER_MINUTE, $this->beginsCountedFor(self::OMAR));

        foreach ([61, 62] as $begin) {
            self::assertSame(UploadRefused::ADDING_TOO_FAST, $this->refusalOf(self::OMAR)->getMessage(), \sprintf('Begin %d', $begin));
        }
    }

    #[Test]
    public function anotherPersonIsNotCountedWithTheOneAtTheLimit(): void
    {
        $this->makeBegins(self::OMAR, self::BEGINS_PER_MINUTE);

        $this->uploadBeginLimit->consume(new UserId(self::LENA));

        self::assertSame(1, $this->beginsCountedFor(self::LENA));
        self::assertSame(self::BEGINS_PER_MINUTE, $this->beginsCountedFor(self::OMAR));
        self::assertSame(UploadRefused::ADDING_TOO_FAST, $this->refusalOf(self::OMAR)->getMessage());
    }

    private function makeBegins(string $userId, int $begins): void
    {
        for ($begin = 1; $begin <= $begins; ++$begin) {
            $this->uploadBeginLimit->consume(new UserId($userId));
        }
    }

    private function refusalOf(string $userId): UploadRefused
    {
        try {
            $this->uploadBeginLimit->consume(new UserId($userId));
        } catch (UploadRefused $refusal) {
            return $refusal;
        }
        self::fail('The begin was accepted.');
    }

    private function beginsCountedFor(string $userId): int
    {
        $window = $this->storage->fetch('media_upload_begin_per_user-'.$userId);
        self::assertInstanceOf(Window::class, $window, \sprintf('No counter for %s.', $userId));

        return $window->getHitCount();
    }
}
