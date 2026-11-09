<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\SessionConfirmations;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Each time is stored as the user id and Unix seconds in the request's session, like the session's own metadata that
 * EndExpiredSession reads. It never migrates the session: migrate(true) would restamp its creation time and so restart
 * the 30-day limit. Holds no state of its own, so it is safe in worker mode (ADR-0008). Every method throws
 * SessionNotFoundException when there is no request with a session.
 */
final readonly class SymfonySessionConfirmations implements SessionConfirmations
{
    public const string RECENT_AUTH_KEY = 'identity.recent_auth_at';
    public const string TWO_STEP_CONFIRMED_KEY = 'identity.two_step_confirmed_at';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function recordRecentAuth(UserId $userId, \DateTimeImmutable $now): void
    {
        $this->record(self::RECENT_AUTH_KEY, $userId, $now);
    }

    public function recordTwoStepConfirmation(UserId $userId, \DateTimeImmutable $now): void
    {
        $this->record(self::TWO_STEP_CONFIRMED_KEY, $userId, $now);
    }

    public function recentAuthAt(UserId $userId): ?\DateTimeImmutable
    {
        return $this->timeAt(self::RECENT_AUTH_KEY, $userId);
    }

    public function twoStepConfirmedAt(UserId $userId): ?\DateTimeImmutable
    {
        return $this->timeAt(self::TWO_STEP_CONFIRMED_KEY, $userId);
    }

    public function forget(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::RECENT_AUTH_KEY);
        $session->remove(self::TWO_STEP_CONFIRMED_KEY);
    }

    private function record(string $key, UserId $userId, \DateTimeImmutable $now): void
    {
        $this->requestStack->getSession()->set($key, ['user_id' => $userId->toString(), 'at' => $now->getTimestamp()]);
    }

    private function timeAt(string $key, UserId $userId): ?\DateTimeImmutable
    {
        $recorded = $this->requestStack->getSession()->get($key);
        if (!\is_array($recorded) || ($recorded['user_id'] ?? null) !== $userId->toString() || !\is_int($recorded['at'] ?? null)) {
            return null;
        }

        return \DateTimeImmutable::createFromTimestamp($recorded['at']);
    }
}
