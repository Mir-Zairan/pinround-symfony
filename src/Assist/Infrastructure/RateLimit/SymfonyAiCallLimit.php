<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\RateLimit;

use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiRateLimited;
use App\Organization\Api\ActorContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter ai_calls_per_user of rate_limiter.yaml, on the state Valkey like the login ones, so it fails closed: when
 * Valkey cannot be reached the call fails instead of running unlimited. Keyed by the organization and the user id
 * (ADR-0018 › Metering).
 */
final readonly class SymfonyAiCallLimit implements AiCallLimit
{
    public function __construct(
        #[Autowire(service: 'limiter.ai_calls_per_user')]
        private RateLimiterFactoryInterface $perUser,
    ) {
    }

    public function consume(ActorContext $caller): void
    {
        if (!$this->limiterOf($caller)->consume()->isAccepted()) {
            throw new AiRateLimited(provider: null);
        }
    }

    public function hasCallsLeft(ActorContext $caller): bool
    {
        // The fixed_window policy of rate_limiter.yaml reads a consume of 0 tokens without saving the window, so it
        // neither counts a call nor opens a window early; another policy may save it.
        return $this->limiterOf($caller)->consume(0)->getRemainingTokens() > 0;
    }

    private function limiterOf(ActorContext $caller): LimiterInterface
    {
        return $this->perUser->create($caller->organizationId()->toString().':'.$caller->userId()->toString());
    }
}
