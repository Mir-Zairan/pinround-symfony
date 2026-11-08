<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

/**
 * Whether the person in this browser session proved who they are recently enough for a sensitive action
 * (RequiresRecentAuth): both their password and a two-step login code, each within WINDOW_SECONDS. A login with the
 * password only records no two-step time, so it never counts.
 */
final readonly class RecentAuth
{
    public const int WINDOW_SECONDS = 600;

    public function __construct(private SessionConfirmations $sessionConfirmations)
    {
    }

    public function isRecentFor(UserId $userId, \DateTimeImmutable $now): bool
    {
        return self::isRecent($this->sessionConfirmations->recentAuthAt($userId), $this->sessionConfirmations->twoStepConfirmedAt($userId), $now);
    }

    /**
     * A proof exactly WINDOW_SECONDS old still counts.
     */
    public static function isRecent(?\DateTimeImmutable $recentAuthAt, ?\DateTimeImmutable $twoStepConfirmedAt, \DateTimeImmutable $now): bool
    {
        return self::isWithinWindow($recentAuthAt, $now) && self::isWithinWindow($twoStepConfirmedAt, $now);
    }

    private static function isWithinWindow(?\DateTimeImmutable $provedAt, \DateTimeImmutable $now): bool
    {
        return null !== $provedAt && $now->getTimestamp() - $provedAt->getTimestamp() <= self::WINDOW_SECONDS;
    }
}
