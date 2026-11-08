<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

interface TwoStepLogins
{
    /**
     * Reads only, for showing it.
     */
    public function byUserId(UserId $userId): ?TwoStepLogin;

    /**
     * For a change: holds the user's row lock until the caller's transaction ends, so two requests of the same user
     * (a double click on "Turn on" or "Confirm") change their two-step login one after the other, and the second one
     * sees what the first one saved.
     *
     * @throws \LogicException when no transaction is open
     */
    public function byUserIdForChange(UserId $userId): ?TwoStepLogin;

    /**
     * Saves a new or changed two-step login at once.
     */
    public function save(TwoStepLogin $twoStepLogin): void;

    /**
     * Deletes it at once, so the user has none and a new setup starts from a new seed. Read it with
     * byUserIdForChange in the same transaction first.
     */
    public function remove(TwoStepLogin $twoStepLogin): void;
}
