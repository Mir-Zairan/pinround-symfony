<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\OAuthClientRegistrationLimiter;
use App\Identity\Application\TooManyOAuthClientRegistrations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiter oauth_client_registration_per_ip of rate_limiter.yaml, on the state Valkey like the login ones (fails
 * closed). Counters are keyed by an HMAC of the client IP, so Valkey does not hold the IP.
 */
final readonly class SymfonyOAuthClientRegistrationLimiter implements OAuthClientRegistrationLimiter
{
    /**
     * @param int $perIpMinutes the interval of oauth_client_registration_per_ip
     */
    public function __construct(
        #[Autowire(service: 'limiter.oauth_client_registration_per_ip')]
        private RateLimiterFactoryInterface $perIp,
        #[Autowire(param: 'identity.oauth_client_registration_per_ip_minutes')]
        private int $perIpMinutes,
        private ThrottleKeyHasher $keyHasher,
    ) {
    }

    public function consume(string $clientIp): void
    {
        $minutesToWait = RefusedTryWait::minutesFor($this->perIp->create($this->keyHasher->hexOf($clientIp))->consume(), $this->perIpMinutes);
        if (null !== $minutesToWait) {
            throw new TooManyOAuthClientRegistrations($minutesToWait);
        }
    }
}
