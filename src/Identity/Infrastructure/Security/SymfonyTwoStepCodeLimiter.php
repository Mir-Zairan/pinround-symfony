<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\TooManyTwoStepCodeTries;
use App\Identity\Application\TwoStepCodeLimiter;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter two_step_code_per_user of rate_limiter.yaml, on the state Valkey like the login ones (fails closed), keyed
 * by an HMAC of the user id. Per user, not per IP, so the guesses at one user's code cannot be spread over addresses.
 */
final readonly class SymfonyTwoStepCodeLimiter implements TwoStepCodeLimiter
{
    /**
     * @param int $perUserMinutes the interval of two_step_code_per_user
     */
    public function __construct(
        #[Autowire(service: 'limiter.two_step_code_per_user')]
        private RateLimiterFactoryInterface $perUser,
        #[Autowire(param: 'identity.two_step_code_per_user_minutes')]
        private int $perUserMinutes,
        private ThrottleKeyHasher $keyHasher,
    ) {
    }

    public function consume(UserId $userId): void
    {
        $limit = $this->perUser->create($this->keyHasher->hexOf('two-step-code-'.$userId->toString()))->consume();
        $minutesToWait = RefusedTryWait::minutesFor($limit, $this->perUserMinutes);
        if (null !== $minutesToWait) {
            throw new TooManyTwoStepCodeTries($minutesToWait);
        }
    }
}
