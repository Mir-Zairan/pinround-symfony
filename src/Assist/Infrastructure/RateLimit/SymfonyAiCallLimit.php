<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\RateLimit;

use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiRateLimited;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter ai_calls_per_user of rate_limiter.yaml, on the state Valkey like the login ones, so it fails closed: when
 * Valkey cannot be reached the call fails instead of running unlimited. Keyed by the user id alone, so one person's
 * calls count together in every organization.
 */
final readonly class SymfonyAiCallLimit implements AiCallLimit
{
    public function __construct(
        #[Autowire(service: 'limiter.ai_calls_per_user')]
        private RateLimiterFactoryInterface $perUser,
    ) {
    }

    public function consume(UserId $userId): void
    {
        if (!$this->perUser->create($userId->toString())->consume()->isAccepted()) {
            throw new AiRateLimited(provider: null);
        }
    }
}
