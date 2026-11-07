<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

interface PasswordChangeLimiter
{
    /**
     * Counts one check of this user's current password, right or wrong.
     *
     * @throws TooManyPasswordChangeTries when this user's current password was checked too often
     */
    public function consume(UserId $userId): void;
}
