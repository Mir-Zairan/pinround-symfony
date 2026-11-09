<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\TwoStepLogins;
use App\Identity\Domain\Users;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\UserId;

/**
 * The two-step login of a user, for the security page and the setup page.
 */
final readonly class ShowTwoStepSetup
{
    public function __construct(
        private TwoStepLogins $twoStepLogins,
        private Users $users,
        private FieldEncryption $fieldEncryption,
        private OneTimeCodes $oneTimeCodes,
    ) {
    }

    /**
     * While it awaits a code, with the seed decrypted for the QR code and the key; never once it is on.
     *
     * @throws UndecryptableField when the stored seed cannot be read back
     * @throws \LogicException    when no user has this id
     */
    public function __invoke(UserId $userId): TwoStepSetup
    {
        $twoStepLogin = $this->twoStepLogins->byUserId($userId);
        $state = self::stateFrom($twoStepLogin);
        if (TwoStepLoginState::AwaitingCode !== $state || null === $twoStepLogin) {
            return new TwoStepSetup($state);
        }

        // The firewall reloads the logged-in user on every request and logs the session out when it is gone.
        $user = $this->users->byId($userId) ?? throw new \LogicException('No user has this id.');
        $seed = $this->fieldEncryption->decrypt($twoStepLogin->encryptedSeed());

        return new TwoStepSetup(
            $state,
            implode(' ', str_split($seed, 4)),
            $this->oneTimeCodes->provisioningUri($seed, $user->email()->toString()),
        );
    }

    /**
     * Without decrypting anything.
     */
    public function stateOf(UserId $userId): TwoStepLoginState
    {
        return self::stateFrom($this->twoStepLogins->byUserId($userId));
    }

    private static function stateFrom(?TwoStepLogin $twoStepLogin): TwoStepLoginState
    {
        return match (true) {
            null === $twoStepLogin => TwoStepLoginState::Off,
            $twoStepLogin->isOn() => TwoStepLoginState::On,
            default => TwoStepLoginState::AwaitingCode,
        };
    }
}
