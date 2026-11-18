<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\RateLimit;

use App\Media\Api\UploadBeginLimit;
use App\Media\Api\UploadRefused;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter media_upload_begin_per_user of rate_limiter.yaml, on the state Valkey like the login ones, so it fails
 * closed: when Valkey cannot be reached the begin fails instead of running unlimited.
 */
final readonly class SymfonyUploadBeginLimit implements UploadBeginLimit
{
    /**
     * @param int $windowMinutes the interval of media_upload_begin_per_user, also the longest wait a refusal tells
     */
    public function __construct(
        #[Autowire(service: 'limiter.media_upload_begin_per_user')]
        private RateLimiterFactoryInterface $beginsPerUser,
        #[Autowire(param: 'media.upload_begin_per_user_minutes')]
        private int $windowMinutes,
    ) {
    }

    public function consume(UserId $userId): void
    {
        $beginLimit = $this->beginsPerUser->create($userId->toString())->consume();
        if (!$beginLimit->isAccepted()) {
            throw UploadRefused::addingTooFast($this->secondsToWaitFor($beginLimit));
        }
    }

    /**
     * At least 1 and at most the window. Read against the wall clock, not ClockInterface, because the limiter counts
     * its window on the wall clock.
     */
    private function secondsToWaitFor(RateLimit $refusedLimit): int
    {
        $secondsLeft = (int) ceil((float) $refusedLimit->getRetryAfter()->format('U.u') - microtime(true));

        return min($this->windowMinutes * 60, max(1, $secondsLeft));
    }
}
