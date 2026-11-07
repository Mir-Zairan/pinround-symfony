<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PasswordChangeLimiter;
use App\Identity\Application\TooManyPasswordChangeTries;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter password_change_per_user of rate_limiter.yaml, on the state Valkey like the login ones (fails closed),
 * keyed by an HMAC of the user id. Per user, not per IP, so a stolen session cannot spread its guesses over addresses.
 */
final readonly class SymfonyPasswordChangeLimiter implements PasswordChangeLimiter
{
    /**
     * @param int $perUserMinutes the interval of password_change_per_user
     */
    public function __construct(
        #[Autowire(service: 'limiter.password_change_per_user')]
        private RateLimiterFactoryInterface $perUser,
        #[Autowire(param: 'identity.password_change_per_user_minutes')]
        private int $perUserMinutes,
        private ThrottleKeyHasher $keyHasher,
    ) {
    }

    public function consume(UserId $userId): void
    {
        $limit = $this->perUser->create($this->keyHasher->hexOf('password-change-'.$userId->toString()))->consume();
        $minutesToWait = RefusedTryWait::minutesFor($limit, $this->perUserMinutes);
        if (null !== $minutesToWait) {
            throw new TooManyPasswordChangeTries($minutesToWait);
        }
    }
}
