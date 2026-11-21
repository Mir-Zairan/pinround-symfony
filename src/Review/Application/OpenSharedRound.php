<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\SharedKernel\Domain\UserId;

/**
 * An open hand-off round of a ticket (Round::isOpenSharedRound), with only what the round bar names: no screenshot, pin
 * or verdict.
 */
final readonly class OpenSharedRound
{
    /**
     * @param int    $number  from 1; a shared round always has one
     * @param string $kind    review or draft, as RoundView's kind
     * @param UserId $ownerId who shared it
     */
    public function __construct(
        public int $number,
        public string $kind,
        public UserId $ownerId,
    ) {
    }
}
