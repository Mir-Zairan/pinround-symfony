<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;

interface PasswordResetMailer
{
    /**
     * Queues the email with the link to /password/reset/{token}; the token is its only secret.
     */
    public function sendLink(User $user, PasswordResetToken $token): void;
}
