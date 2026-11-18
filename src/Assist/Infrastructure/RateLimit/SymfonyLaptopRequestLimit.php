<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\RateLimit;

use App\Assist\Application\LaptopRequestLimit;
use App\Assist\Application\LaptopRequestsLimited;
use App\Organization\Api\ActorContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter ai_laptop_per_user of rate_limiter.yaml, on the state Valkey like the login ones, so it fails closed: when
 * Valkey cannot be reached the request fails instead of running unlimited.
 *
 * The key holds the minute of the clock service as well as the organization and the user. The limiter counts its own
 * window on the wall clock from the first request; a key per clock minute ends each count when the minute does, a
 * window that the clock service decides and a frozen clock can move. The limiter's 1-minute window outlives its key's
 * minute, so it never resets a count within it.
 */
final readonly class SymfonyLaptopRequestLimit implements LaptopRequestLimit
{
    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        #[Autowire(service: 'limiter.ai_laptop_per_user')]
        private RateLimiterFactoryInterface $perUser,
        private ClockInterface $clock,
    ) {
    }

    public function consume(ActorContext $caller): void
    {
        $now = $this->clock->now()->getTimestamp();
        $minute = intdiv($now, self::SECONDS_PER_MINUTE);
        $key = $caller->organizationId()->toString().':'.$caller->userId()->toString().':'.$minute;
        if (!$this->perUser->create($key)->consume()->isAccepted()) {
            throw new LaptopRequestsLimited(self::SECONDS_PER_MINUTE - ($now - $minute * self::SECONDS_PER_MINUTE));
        }
    }
}
