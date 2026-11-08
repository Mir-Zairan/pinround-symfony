<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RecoveryCodeHashing;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\UserId;

/**
 * The person types the code their app shows for the new seed, and two-step login is on. Its writes share the caller's
 * transaction (RequestTransaction on the web).
 */
final readonly class ConfirmTwoStepSetup
{
    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private FieldEncryption $fieldEncryption,
        private OneTimeCodes $oneTimeCodes,
        private RecoveryCodeHashing $recoveryCodeHashing,
    ) {
    }

    /**
     * Spaces in the typed code are ignored. Tries are not counted: the person already sees the seed on the page.
     *
     * @return RecoveryCodes|TwoStepConfirmRefusal the recovery codes in plain text, which are never available again
     *
     * @throws UndecryptableField when the stored seed cannot be read back
     * @throws \LogicException    when no transaction is open
     */
    public function __invoke(UserId $userId, #[\SensitiveParameter] string $typedCode, \DateTimeImmutable $now): RecoveryCodes|TwoStepConfirmRefusal
    {
        $twoStepLogin = $this->twoStepLogins->byUserIdForChange($userId);
        if (null === $twoStepLogin) {
            return TwoStepConfirmRefusal::NotStarted;
        }
        if ($twoStepLogin->isOn()) {
            return TwoStepConfirmRefusal::AlreadyOn;
        }

        $code = preg_replace('/\s+/', '', $typedCode) ?? '';
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            return TwoStepConfirmRefusal::WrongCode;
        }
        $matchedStep = $this->oneTimeCodes->matchingStep($this->fieldEncryption->decrypt($twoStepLogin->encryptedSeed()), $code, $now);
        if (null === $matchedStep) {
            return TwoStepConfirmRefusal::WrongCode;
        }

        $recoveryCodes = RecoveryCodes::generate();
        $twoStepLogin->confirm($matchedStep, $now, array_map($this->recoveryCodeHashing->hash(...), $recoveryCodes->reveal()));
        $this->twoStepLogins->save($twoStepLogin);

        return $recoveryCodes;
    }
}
