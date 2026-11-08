<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\RateLimiter\RateLimit;

/**
 * The wait a refused password reset request, password change or two-step login code is told
 * (SymfonyPasswordResetRequestLimiter, SymfonyPasswordChangeLimiter, SymfonyTwoStepCodeLimiter).
 */
final class RefusedTryWait
{
    /**
     * @return int|null null when accepted; else whole minutes, at least 1 and at most the interval, because the fixed
     *                  window counts from the start of the current second and could otherwise read one minute more
     */
    public static function minutesFor(RateLimit $limit, int $intervalMinutes): ?int
    {
        if ($limit->isAccepted()) {
            return null;
        }

        return min($intervalMinutes, max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60)));
    }
}
