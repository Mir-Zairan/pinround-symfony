<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The firewall's login throttle (security.yaml), from the limiters of rate_limiter.yaml. Every try takes a token from
 * both limiters before the password check. It is not peekable on purpose: Symfony charges a peekable limiter only
 * after a failure, so every try of a burst that arrives before the first failure is stored would get its password
 * checked. A successful login gives its per-IP try back, so only wrong tries fill the per-IP limit, and the tries still
 * being checked count too: a burst across many emails gets at most 50 password checks.
 *
 * Counters are keyed by an HMAC of the client IP, and of the lowercased email with the IP, so Valkey holds neither.
 * A refusal waits at most the refusing limiter's own interval: Symfony's fixed window counts from the start of the
 * current second, which would otherwise make a 15-minute window read "16 minutes".
 */
final readonly class ConsumeFirstLoginRateLimiter implements RequestRateLimiterInterface
{
    /** The name of the per-IP limiter in rate_limiter.yaml, which RateLimiterFactory puts first in its counter id. */
    private const string PER_IP_LIMITER_NAME = 'login_per_ip';

    /**
     * @param int $perEmailAndIpMinutes the interval of login_per_email_and_ip
     * @param int $perIpMinutes         the interval of login_per_ip
     */
    public function __construct(
        #[Autowire(service: 'limiter.login_per_email_and_ip')]
        private RateLimiterFactoryInterface $perEmailAndIp,
        #[Autowire(param: 'identity.login_per_email_and_ip_minutes')]
        private int $perEmailAndIpMinutes,
        #[Autowire(service: 'limiter.'.self::PER_IP_LIMITER_NAME)]
        private RateLimiterFactoryInterface $perIp,
        #[Autowire(param: 'identity.login_per_ip_minutes')]
        private int $perIpMinutes,
        private FixedWindowTryRefund $perIpRefund,
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * Takes one try from each limiter, also when the other one refuses. Returns the refusal that waits longest, or
     * else the acceptance with the fewest tries left.
     */
    public function consume(Request $request): RateLimit
    {
        $answers = [
            self::cappedAt($this->perIpLimiter($request)->consume(), $this->perIpMinutes),
            self::cappedAt($this->perEmailAndIpLimiter($request)->consume(), $this->perEmailAndIpMinutes),
        ];
        usort($answers, static fn (RateLimit $a, RateLimit $b): int => [$a->isAccepted(), $b->getRetryAfter(), $a->getRemainingTokens()] <=> [$b->isAccepted(), $a->getRetryAfter(), $b->getRemainingTokens()]);

        return $answers[0];
    }

    /**
     * After a successful login: clears the email and IP counter, and gives back the one try this login took from the IP
     * counter. The IP counter bounds how many emails one client may try, so its earlier wrong tries stay.
     */
    public function reset(Request $request): void
    {
        $this->perEmailAndIpLimiter($request)->reset();
        $this->perIpRefund->refundOneTry(self::PER_IP_LIMITER_NAME.'-'.$this->perIpKey($request));
    }

    private function perIpLimiter(Request $request): LimiterInterface
    {
        return $this->perIp->create($this->perIpKey($request));
    }

    private function perIpKey(Request $request): string
    {
        return $this->hash((string) $request->getClientIp());
    }

    private function perEmailAndIpLimiter(Request $request): LimiterInterface
    {
        $email = $request->attributes->getString(SecurityRequestAttributes::LAST_USERNAME);
        $lowercaseEmail = mb_check_encoding($email, 'UTF-8') ? mb_strtolower($email, 'UTF-8') : strtolower($email);

        return $this->perEmailAndIp->create($this->hash($lowercaseEmail.'-'.$request->getClientIp()));
    }

    private function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret);
    }

    private static function cappedAt(RateLimit $limit, int $minutes): RateLimit
    {
        $latestRetry = new \DateTimeImmutable('@'.(time() + 60 * $minutes));
        if ($limit->isAccepted() || $limit->getRetryAfter() <= $latestRetry) {
            return $limit;
        }

        return new RateLimit($limit->getRemainingTokens(), $latestRetry, false, $limit->getLimit());
    }
}
