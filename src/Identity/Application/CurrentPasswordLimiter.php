<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

/**
 * Bounds the re-checks of a logged-in user's password: on the security page and at "Confirm it's you", one count.
 */
interface CurrentPasswordLimiter
{
    /**
     * Counts one check of this user's current password, right or wrong.
     *
     * @throws TooManyCurrentPasswordTries when this user's current password was checked too often
     */
    public function consume(UserId $userId): void;
}
