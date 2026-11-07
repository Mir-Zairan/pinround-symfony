<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;

interface PasswordResetMailer
{
    /**
     * Sends the email with the link to /password/reset/{token} at once; the token is its only secret.
     *
     * @throws \RuntimeException when the mail server refuses or cannot be reached
     */
    public function sendLink(User $user, PasswordResetToken $token): void;
}
