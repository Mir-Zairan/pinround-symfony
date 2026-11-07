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
use App\SharedKernel\Application\OnceOnlyHandling;

/**
 * Handles a queued EmailPasswordResetLink: when an active user with a password has the email, it saves a reset that
 * works for one hour from $now and emails its link; for any other email it does nothing. It runs once per message
 * (OnceOnlyHandling), so a redelivered message neither adds a second reset nor sends a second email, unless the
 * email was sent and the transaction then failed to commit.
 */
final readonly class SendPasswordResetLink
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered requests would send again. */
    public const string HANDLER_NAME = 'identity.send_password_reset_link';

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private Users $users,
        private Credentials $credentials,
        private PasswordResets $passwordResets,
        private PasswordResetMailer $mailer,
    ) {
    }

    public function __invoke(EmailPasswordResetLink $request, \DateTimeImmutable $now): void
    {
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($request, $now): void {
            try {
                $user = $this->users->byEmail(new Email($request->email));
            } catch (InvalidEmail) {
                return;
            }
            if (null === $user || UserStatus::Active !== $user->status() || null === $this->credentials->byUserId($user->id())) {
                return;
            }

            $token = PasswordResetToken::generate();
            $this->passwordResets->add(new PasswordReset($this->passwordResets->nextIdentity(), $user, $token, $now));
            // Last, so a failed send rolls the reset back and the next delivery tries again.
            $this->mailer->sendLink($user, $token);
        });
    }
}
