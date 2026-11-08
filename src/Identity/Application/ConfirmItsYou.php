<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\UserId;

/**
 * "Confirm it's you" (ADR-0012): a logged-in person whose two-step login is on types their password and a code again
 * before a sensitive action (RecentAuth). Its writes share the caller's transaction (RequestTransaction on the web).
 */
final readonly class ConfirmItsYou
{
    public function __construct(
        private CurrentPasswordCheck $currentPasswordCheck,
        private AcceptTwoStepCode $acceptTwoStepCode,
        private SessionConfirmations $sessionConfirmations,
    ) {
    }

    /**
     * The password is checked first, and a wrong one returns before the code is looked at, so the code stays unspent
     * and takes no try of the code limiter. Both right: records both session times at $now, so RecentAuth counts from
     * here, and keeps the session id.
     *
     * @return bool true when the password and the code were right; false when either was wrong or two-step login is off
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
    ): bool {
        if (!$this->currentPasswordCheck->matches($userId, $password) || !($this->acceptTwoStepCode)($userId, $typedCode, $now)) {
            return false;
        }

        $this->sessionConfirmations->recordRecentAuth($userId, $now);
        $this->sessionConfirmations->recordTwoStepConfirmation($userId, $now);

        return true;
    }
}
