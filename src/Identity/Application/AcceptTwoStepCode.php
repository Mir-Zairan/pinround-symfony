<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RecoveryCodeHashing;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\UserId;

/**
 * Checks a code typed for a user whose two-step login is on, and spends it: an authenticator code's step counts as
 * used, so the same code never counts twice, and a recovery code's hash is removed. Its writes share the caller's
 * transaction (RequestTransaction on the web).
 */
final readonly class AcceptTwoStepCode
{
    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private FieldEncryption $fieldEncryption,
        private OneTimeCodes $oneTimeCodes,
        private RecoveryCodeHashing $recoveryCodeHashing,
        private TwoStepCodeLimiter $limiter,
    ) {
    }

    /**
     * Every try counts against the limiter, before anything is checked. A 6-digit code is taken as an authenticator
     * code, anything else as a recovery code; spaces are ignored, and in a recovery code also the dash and the case.
     *
     * @return bool true when the code was right and is now spent; false when it is wrong or used already, or two-step
     *              login is off
     *
     * @throws TooManyTwoStepCodeTries when codes were typed for this user too often
     * @throws UndecryptableField      when the stored seed cannot be read back
     * @throws \LogicException         when no transaction is open
     */
    public function __invoke(UserId $userId, #[\SensitiveParameter] string $typedCode, \DateTimeImmutable $now): bool
    {
        $this->limiter->consume($userId);

        $twoStepLogin = $this->twoStepLogins->byUserIdForChange($userId);
        if (null === $twoStepLogin || !$twoStepLogin->isOn()) {
            return false;
        }

        $code = preg_replace('/\s+/', '', $typedCode) ?? '';
        $isAccepted = 1 === preg_match('/^\d{6}$/', $code)
            ? $this->acceptOneTimeCode($twoStepLogin, $code, $now)
            : $this->spendRecoveryCode($twoStepLogin, $code);
        if ($isAccepted) {
            $this->twoStepLogins->save($twoStepLogin);
        }

        return $isAccepted;
    }

    private function acceptOneTimeCode(TwoStepLogin $twoStepLogin, #[\SensitiveParameter] string $code, \DateTimeImmutable $now): bool
    {
        $matchedStep = $this->oneTimeCodes->matchingStep($this->fieldEncryption->decrypt($twoStepLogin->encryptedSeed()), $code, $now);

        return null !== $matchedStep && $twoStepLogin->acceptCode($matchedStep);
    }

    /**
     * Each check is an Argon2id verify, so only input in the shape of a recovery code ("k7m2q-x9p4t", RecoveryCodes)
     * is checked against the hashes.
     */
    private function spendRecoveryCode(TwoStepLogin $twoStepLogin, #[\SensitiveParameter] string $code): bool
    {
        $characters = strtolower(str_replace('-', '', $code));
        if (1 !== preg_match('/^[0-9a-z]{10}$/', $characters)) {
            return false;
        }
        $recoveryCode = substr($characters, 0, 5).'-'.substr($characters, 5);

        foreach ($twoStepLogin->recoveryCodeHashes() as $hash) {
            if ($this->recoveryCodeHashing->matches($hash, $recoveryCode)) {
                $twoStepLogin->spendRecoveryCode($hash);

                return true;
            }
        }

        return false;
    }
}
