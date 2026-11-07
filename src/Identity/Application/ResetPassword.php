<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\PasswordResets;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\PasswordTooShort;
use App\Identity\Domain\Users;
use App\Identity\Domain\UserStatus;

/**
 * Sets the password a person chose through a reset link and ends every session of that user (FR-ID-08, ADR-0012).
 * Its writes share the caller's transaction (RequestTransaction on the web), so the link is used up only together
 * with the new password.
 */
final readonly class ResetPassword
{
    public const int MINIMUM_PASSWORD_LENGTH = NewPassword::MINIMUM_LENGTH;

    public function __construct(
        private PasswordResets $passwordResets,
        private Users $users,
        private Credentials $credentials,
        private PasswordHashing $passwordHashing,
    ) {
    }

    /**
     * Whether the link still works at $now, to decide whether to show the form at all.
     */
    public function isLinkUsable(#[\SensitiveParameter] string $token, \DateTimeImmutable $now): bool
    {
        return $this->passwordResets->isUsable(PasswordResetToken::fromLink($token), $now);
    }

    public function __invoke(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $newPassword, \DateTimeImmutable $now): PasswordResetOutcome
    {
        try {
            $password = new NewPassword($newPassword);
        } catch (PasswordTooShort) {
            return PasswordResetOutcome::PasswordTooShort;
        }

        $userId = $this->passwordResets->consume(PasswordResetToken::fromLink($token), $now);
        if (null === $userId) {
            return PasswordResetOutcome::LinkNoLongerWorks;
        }
        $user = $this->users->byId($userId);
        $credential = $this->credentials->byUserId($userId);
        // Blocked, or the password removed, after the link was sent: the link is used up but changes nothing.
        if (null === $user || null === $credential || UserStatus::Active !== $user->status()) {
            return PasswordResetOutcome::LinkNoLongerWorks;
        }

        $credential->changePassword($this->passwordHashing->hash($password), $now);
        $this->credentials->save($credential);
        $user->rotateSessionStamp();
        $this->users->save($user);

        return PasswordResetOutcome::PasswordChanged;
    }
}
