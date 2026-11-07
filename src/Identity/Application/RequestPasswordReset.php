<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResets;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\Users;
use App\Identity\Domain\UserStatus;

/**
 * "Forgot password" (FR-ID-08): sends a link to choose a new password, valid for one hour, to an active user who has a
 * password. The caller learns nothing about the email: an unknown, blocked or password-less one returns the same way
 * after the same lookups, and the email itself is queued, so the response does not wait for it either.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        private PasswordResetRequestLimiter $limiter,
        private Users $users,
        private Credentials $credentials,
        private PasswordResets $passwordResets,
        private PasswordResetMailer $mailer,
    ) {
    }

    /**
     * @throws TooManyPasswordResetRequests before anything is looked up or sent
     */
    public function __invoke(string $email, string $clientIp, \DateTimeImmutable $now): void
    {
        $this->limiter->consume($email, $clientIp);

        try {
            $user = $this->users->byEmail(new Email($email));
        } catch (InvalidEmail) {
            return;
        }
        $credential = null === $user ? null : $this->credentials->byUserId($user->id());
        if (null === $user || null === $credential || UserStatus::Active !== $user->status()) {
            return;
        }

        $token = PasswordResetToken::generate();
        $this->passwordResets->add(new PasswordReset($this->passwordResets->nextIdentity(), $user, $token, $now));
        $this->mailer->sendLink($user, $token);
    }
}
