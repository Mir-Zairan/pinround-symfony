<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

interface TwoStepCodeLimiter
{
    /**
     * Counts one check of a code typed for this user's two-step login, right or wrong.
     *
     * @throws TooManyTwoStepCodeTries when codes were typed for this user too often
     */
    public function consume(UserId $userId): void;
}
