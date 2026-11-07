<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PasswordResetRequestLimiter;
use App\Identity\Application\TooManyPasswordResetRequests;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The limiters password_reset_per_email_and_ip and password_reset_per_ip of rate_limiter.yaml, on the state Valkey
 * like the login ones (fails closed). Counters are keyed by an HMAC of the client IP, and of the normalized email with
 * the IP, so Valkey holds neither.
 */
final readonly class SymfonyPasswordResetRequestLimiter implements PasswordResetRequestLimiter
{
    /**
     * @param int $perEmailAndIpMinutes the interval of password_reset_per_email_and_ip
     * @param int $perIpMinutes         the interval of password_reset_per_ip
     */
    public function __construct(
        #[Autowire(service: 'limiter.password_reset_per_email_and_ip')]
        private RateLimiterFactoryInterface $perEmailAndIp,
        #[Autowire(param: 'identity.password_reset_per_email_and_ip_minutes')]
        private int $perEmailAndIpMinutes,
        #[Autowire(service: 'limiter.password_reset_per_ip')]
        private RateLimiterFactoryInterface $perIp,
        #[Autowire(param: 'identity.password_reset_per_ip_minutes')]
        private int $perIpMinutes,
        private ThrottleKeyHasher $keyHasher,
    ) {
    }

    /**
     * Takes one request from each limiter, also when the other one refuses, and names the longer wait of the two.
     */
    public function consume(string $email, string $clientIp): void
    {
        $waits = array_filter([
            RefusedTryWait::minutesFor($this->perIp->create($this->keyHasher->hexOf($clientIp))->consume(), $this->perIpMinutes),
            RefusedTryWait::minutesFor(
                $this->perEmailAndIp->create($this->keyHasher->hexOf(ThrottleKeyHasher::normalizeEmail($email).'-'.$clientIp))->consume(),
                $this->perEmailAndIpMinutes,
            ),
        ], static fn (?int $minutes): bool => null !== $minutes);
        if ([] !== $waits) {
            throw new TooManyPasswordResetRequests(max($waits));
        }
    }
}
