<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\UserId;

/**
 * A logged-in person turns their two-step login off with their password and a code (ADR-0012). The row goes, so a
 * new setup starts from a new seed, and the session's proofs go with it. Its writes share the caller's transaction
 * (RequestTransaction on the web).
 */
final readonly class DisableTwoStepLogin
{
    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private CurrentPasswordCheck $currentPasswordCheck,
        private AcceptTwoStepCode $acceptTwoStepCode,
        private SessionConfirmations $sessionConfirmations,
    ) {
    }

    /**
     * Holds the user's row lock from the first read to the end of the transaction. While it is off, nothing is checked
     * and no try is taken. The password is checked before the code, and a wrong one returns before the code is looked
     * at, so the code stays unspent and takes no try of the code limiter. Both right: removes both session times, so
     * admin pages and actions marked RequiresRecentAuth ask again.
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
    ): TwoStepLoginOffOutcome {
        $twoStepLogin = $this->twoStepLogins->byUserIdForChange($userId);
        if (null === $twoStepLogin || !$twoStepLogin->isOn()) {
            return TwoStepLoginOffOutcome::AlreadyOff;
        }
        if (!$this->currentPasswordCheck->matches($userId, $password) || !($this->acceptTwoStepCode)($userId, $typedCode, $now)) {
            return TwoStepLoginOffOutcome::WrongPasswordOrCode;
        }

        $this->twoStepLogins->remove($twoStepLogin);
        $this->sessionConfirmations->forget();

        return TwoStepLoginOffOutcome::TurnedOff;
    }
}
