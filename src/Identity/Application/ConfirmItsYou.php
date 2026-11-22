<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\UserId;

/**
 * "Confirm it's you" (ADR-0012): a logged-in person whose two-step login is on types their password and a code again
 * before a sensitive action (RecentAuth). With the demo switch on in dev (ADR-0049), it asks for the password only,
 * whether two-step login is on or off. Its writes share the caller's transaction (RequestTransaction on the web).
 */
final readonly class ConfirmItsYou
{
    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private CurrentPasswordCheck $currentPasswordCheck,
        private AcceptTwoStepCode $acceptTwoStepCode,
        private SessionConfirmations $sessionConfirmations,
        private RecentAuth $recentAuth,
    ) {
    }

    /**
     * Whether the page asks for a code from the app as well as the password; false only while the demo switch skips
     * two-step login, so a person without two-step login is then asked for the password instead of sent to its setup.
     */
    public function asksForTwoStepCode(): bool
    {
        return !$this->recentAuth->isTwoStepLoginSkippedForDemo();
    }

    /**
     * Holds the user's row lock from the first read to the end of the transaction. While two-step login is off,
     * nothing is checked and no try is taken. The password is checked first, and a wrong one returns before the code
     * is looked at, so the code stays unspent and takes no try of the code limiter. Both right: records both session
     * times at $now, so RecentAuth counts from here, and keeps the session id.
     *
     * While asksForTwoStepCode() is false, only the password is checked and $typedCode is ignored; a right one records
     * the password time only, never a two-step time, so no code proof outlives the demo switch.
     *
     * @throws TooManyCurrentPasswordTries when this user's password was checked too often
     * @throws TooManyTwoStepCodeTries     when codes were typed for this user too often
     * @throws UndecryptableField          when the stored seed cannot be read back
     * @throws \LogicException             when no transaction is open
     */
    public function __invoke(
        UserId $userId,
        #[\SensitiveParameter]
        string $password,
        #[\SensitiveParameter]
        string $typedCode,
        \DateTimeImmutable $now,
    ): ConfirmItsYouOutcome {
        if (!$this->asksForTwoStepCode()) {
            return $this->confirmThePasswordOnly($userId, $password, $now);
        }
        if (true !== $this->twoStepLogins->byUserIdForChange($userId)?->isOn()) {
            return ConfirmItsYouOutcome::TwoStepLoginOff;
        }
        if (!$this->currentPasswordCheck->matches($userId, $password) || !($this->acceptTwoStepCode)($userId, $typedCode, $now)) {
            return ConfirmItsYouOutcome::WrongPasswordOrCode;
        }

        $this->sessionConfirmations->recordRecentAuth($userId, $now);
        $this->sessionConfirmations->recordTwoStepConfirmation($userId, $now);

        return ConfirmItsYouOutcome::Confirmed;
    }

    /**
     * @throws TooManyCurrentPasswordTries when this user's password was checked too often
     */
    private function confirmThePasswordOnly(UserId $userId, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): ConfirmItsYouOutcome
    {
        if (!$this->currentPasswordCheck->matches($userId, $password)) {
            return ConfirmItsYouOutcome::WrongPassword;
        }
        $this->sessionConfirmations->recordRecentAuth($userId, $now);

        return ConfirmItsYouOutcome::Confirmed;
    }
}
