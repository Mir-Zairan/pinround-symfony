<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * When the person in the browser session of the request last proved who they are: with their password, and with a
 * two-step login code. Recording a time never gives the session a new id.
 */
interface SessionConfirmations
{
    /**
     * At a login and at every "Confirm it's you": the password was right.
     */
    public function recordRecentAuth(\DateTimeImmutable $now): void;

    /**
     * At a login with a code, at "Confirm it's you" with a code, and when two-step login is turned on.
     */
    public function recordTwoStepConfirmation(\DateTimeImmutable $now): void;

    /**
     * @return \DateTimeImmutable|null to the second, in UTC; null when this session never recorded one
     */
    public function recentAuthAt(): ?\DateTimeImmutable;

    /**
     * @return \DateTimeImmutable|null to the second, in UTC; null when this session never recorded one
     */
    public function twoStepConfirmedAt(): ?\DateTimeImmutable;
}
