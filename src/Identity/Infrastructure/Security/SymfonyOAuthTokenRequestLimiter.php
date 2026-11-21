<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\OAuthTokenRequestLimiter;
use App\Identity\Application\TooManyOAuthTokenRequests;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter oauth_token_per_ip of rate_limiter.yaml, on the state Valkey like the login ones (fails closed).
 * Counters are keyed by an HMAC of the client IP, so Valkey does not hold the IP.
 */
final readonly class SymfonyOAuthTokenRequestLimiter implements OAuthTokenRequestLimiter
{
    /**
     * @param int $perIpMinutes the interval of oauth_token_per_ip
     */
    public function __construct(
        #[Autowire(service: 'limiter.oauth_token_per_ip')]
        private RateLimiterFactoryInterface $perIp,
        #[Autowire(param: 'identity.oauth_token_per_ip_minutes')]
        private int $perIpMinutes,
        private ThrottleKeyHasher $keyHasher,
    ) {
    }

    public function consume(string $clientIp): void
    {
        $minutesToWait = RefusedTryWait::minutesFor($this->perIp->create($this->keyHasher->hexOf($clientIp))->consume(), $this->perIpMinutes);
        if (null !== $minutesToWait) {
            throw new TooManyOAuthTokenRequests($minutesToWait);
        }
    }
}
