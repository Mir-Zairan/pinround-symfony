<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\SessionConfirmations;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The times as Unix seconds in the request's session, like the session's own metadata that EndExpiredSession reads.
 * It never migrates the session: migrate(true) would restamp its creation time and so restart the 30-day limit.
 * Holds no state of its own, so it is safe in worker mode (ADR-0008). Every method throws SessionNotFoundException
 * when there is no request with a session.
 */
final readonly class SymfonySessionConfirmations implements SessionConfirmations
{
    public const string RECENT_AUTH_KEY = 'identity.recent_auth_at';
    public const string TWO_STEP_CONFIRMED_KEY = 'identity.two_step_confirmed_at';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function recordRecentAuth(\DateTimeImmutable $now): void
    {
        $this->requestStack->getSession()->set(self::RECENT_AUTH_KEY, $now->getTimestamp());
    }

    public function recordTwoStepConfirmation(\DateTimeImmutable $now): void
    {
        $this->requestStack->getSession()->set(self::TWO_STEP_CONFIRMED_KEY, $now->getTimestamp());
    }

    public function recentAuthAt(): ?\DateTimeImmutable
    {
        return $this->timeAt(self::RECENT_AUTH_KEY);
    }

    public function twoStepConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->timeAt(self::TWO_STEP_CONFIRMED_KEY);
    }

    private function timeAt(string $key): ?\DateTimeImmutable
    {
        $timestamp = $this->requestStack->getSession()->get($key);

        return \is_int($timestamp) ? \DateTimeImmutable::createFromTimestamp($timestamp) : null;
    }
}
