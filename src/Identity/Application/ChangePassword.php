<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\PasswordTooShort;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * A logged-in person replaces their password on the security page (FR-ID-06, ADR-0012). It rotates the session stamp,
 * which ends every session of the user; the caller keeps its own session through CurrentSession. Its writes share the
 * caller's transaction (RequestTransaction on the web).
 */
final readonly class ChangePassword
{
    public const int MINIMUM_PASSWORD_LENGTH = NewPassword::MINIMUM_LENGTH;

    public function __construct(
        private Users $users,
        private Credentials $credentials,
        private PasswordHashing $passwordHashing,
        private PasswordChangeLimiter $limiter,
    ) {
    }

    /**
     * A too-short new password is refused before the current one is checked, so it takes no try.
     *
     * @throws TooManyPasswordChangeTries before the current password is checked
     */
    public function __invoke(
        UserId $userId,
        #[\SensitiveParameter]
        string $currentPassword,
        #[\SensitiveParameter]
        string $newPassword,
        \DateTimeImmutable $now,
    ): PasswordChangeOutcome {
        try {
            $password = new NewPassword($newPassword);
        } catch (PasswordTooShort) {
            return PasswordChangeOutcome::PasswordTooShort;
        }

        $this->limiter->consume($userId);
        $user = $this->users->byId($userId);
        $credential = $this->credentials->byUserId($userId);
        if (null === $user || null === $credential || !$this->passwordHashing->matches($credential->passwordHash(), $currentPassword)) {
            return PasswordChangeOutcome::WrongCurrentPassword;
        }

        $credential->changePassword($this->passwordHashing->hash($password), $now);
        $this->credentials->save($credential);
        $user->rotateSessionStamp();
        $this->users->save($user);

        return PasswordChangeOutcome::PasswordChanged;
    }
}
