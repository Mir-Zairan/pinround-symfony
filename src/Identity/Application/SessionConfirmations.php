<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

/**
 * When the person in the browser session of the request last proved who they are: with their password, and with a
 * two-step login code. Each time belongs to the user it was recorded for, so a time never counts for another user of
 * the same browser session. Recording a time never gives the session a new id.
 */
interface SessionConfirmations
{
    /**
     * At a login and at every "Confirm it's you": the password was right.
     */
    public function recordRecentAuth(UserId $userId, \DateTimeImmutable $now): void;

    /**
     * At a login with a code, at "Confirm it's you" with a code, and when two-step login is turned on.
     */
    public function recordTwoStepConfirmation(UserId $userId, \DateTimeImmutable $now): void;

    /**
     * @return \DateTimeImmutable|null to the second, in UTC; null when this session never recorded one for this user
     */
    public function recentAuthAt(UserId $userId): ?\DateTimeImmutable;

    /**
     * @return \DateTimeImmutable|null to the second, in UTC; null when this session never recorded one for this user
     */
    public function twoStepConfirmedAt(UserId $userId): ?\DateTimeImmutable;

    /**
     * At the right password of a new login, and when two-step login is turned off: removes both times, whoever they
     * were recorded for.
     */
    public function forget(): void;
}
