<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\TwoStepLogins;
use App\Identity\Domain\Users;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Domain\UserId;

/**
 * A logged-in person turns two-step login on: a new seed, stored encrypted, which waits for a code from their app.
 * It is still off until ConfirmTwoStepSetup. Its writes share the caller's transaction (RequestTransaction on the web).
 */
final readonly class StartTwoStepSetup
{
    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private Users $users,
        private FieldEncryption $fieldEncryption,
        private OneTimeCodes $oneTimeCodes,
    ) {
    }

    /**
     * A second start before the confirmation replaces the seed, so a QR code shown earlier stops working. Once it is
     * on, this changes nothing.
     *
     * @throws \LogicException when no user has this id, or no transaction is open
     */
    public function __invoke(UserId $userId, \DateTimeImmutable $now): void
    {
        $twoStepLogin = $this->twoStepLogins->byUserIdForChange($userId);
        if (null !== $twoStepLogin && $twoStepLogin->isOn()) {
            return;
        }

        $encryptedSeed = $this->fieldEncryption->encrypt($this->oneTimeCodes->newSeed());
        if (null === $twoStepLogin) {
            $user = $this->users->byId($userId) ?? throw new \LogicException('No user has this id.');
            $twoStepLogin = new TwoStepLogin($user, $encryptedSeed, $now);
        } else {
            $twoStepLogin->start($encryptedSeed, $now);
        }
        $this->twoStepLogins->save($twoStepLogin);
    }
}
