<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Domain\UserId;

/**
 * Whether the person in this browser session proved who they are recently enough: for a sensitive action
 * (RequiresRecentAuth) both their password and a two-step login code, each within WINDOW_SECONDS; for the admin pages
 * the code alone, within the admin window (LoginAssurance). A login with the password only records no two-step time,
 * so it never counts. A code proof counts only while two-step login is on and only from the second it was last turned
 * on, so turning it off or setting it up again in another browser ends the proofs of every session.
 *
 * The demo switch PINROUND_DEMO_SKIP_2FA is read here and nowhere else (ADR-0049): on in the dev environment only, it
 * makes every two-step proof count, and nothing else; the recent password of isRecentFor is still needed. The kernel
 * refuses to boot with it on outside dev, and this class checks the environment again, so neither check alone lets a
 * real deployment skip two-step login.
 */
final readonly class RecentAuth
{
    public const int WINDOW_SECONDS = 600;

    private const string DEMO_ENVIRONMENT = 'dev';

    /**
     * @param bool   $isDemoSwitchOn whether PINROUND_DEMO_SKIP_2FA is on; off unless config/services.yaml wires it
     * @param string $appEnvironment the kernel's environment; the switch counts only in "dev"
     */
    public function __construct(
        private SessionConfirmations $sessionConfirmations,
        private TwoStepLogins $twoStepLogins,
        private bool $isDemoSwitchOn = false,
        private string $appEnvironment = '',
    ) {
    }

    /**
     * Whether the demo switch is on in the dev environment, so a two-step proof is never needed. The page banner
     * reads it, so it shows exactly when the skip applies.
     */
    public function isTwoStepLoginSkippedForDemo(): bool
    {
        return $this->isDemoSwitchOn && self::DEMO_ENVIRONMENT === $this->appEnvironment;
    }

    /**
     * A proof exactly WINDOW_SECONDS old still counts.
     */
    public function isRecentFor(UserId $userId, \DateTimeImmutable $now): bool
    {
        return self::isWithin($this->sessionConfirmations->recentAuthAt($userId), self::WINDOW_SECONDS, $now)
            && $this->hasConfirmedTwoStepLoginWithin($userId, self::WINDOW_SECONDS, $now);
    }

    /**
     * A proof exactly $seconds old still counts.
     */
    public function hasConfirmedTwoStepLoginWithin(UserId $userId, int $seconds, \DateTimeImmutable $now): bool
    {
        if ($this->isTwoStepLoginSkippedForDemo()) {
            return true;
        }

        // Null while two-step login is off: TwoStepLogin::isOn reads the same time.
        $turnedOnAt = $this->twoStepLogins->byUserId($userId)?->confirmedAt();
        $codeConfirmedAt = $this->sessionConfirmations->twoStepConfirmedAt($userId);

        // Compared in whole seconds, as both are stored: the setup's own proof falls in the second it turned it on.
        return null !== $turnedOnAt && null !== $codeConfirmedAt
            && $codeConfirmedAt->getTimestamp() >= $turnedOnAt->getTimestamp()
            && self::isWithin($codeConfirmedAt, $seconds, $now);
    }

    private static function isWithin(?\DateTimeImmutable $provedAt, int $seconds, \DateTimeImmutable $now): bool
    {
        return null !== $provedAt && $now->getTimestamp() - $provedAt->getTimestamp() <= $seconds;
    }
}
