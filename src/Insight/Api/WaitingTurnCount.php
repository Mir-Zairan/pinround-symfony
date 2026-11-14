<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * The open tickets whose turn is one person's, as one viewer may count them.
 */
final readonly class WaitingTurnCount
{
    /**
     * @param int                     $tickets            0 when nothing the viewer may see waits on the person
     * @param \DateTimeImmutable|null $oldestWaitingSince the earliest waiting_since of those tickets; null when there are none
     */
    public function __construct(public int $tickets, public ?\DateTimeImmutable $oldestWaitingSince)
    {
    }
}
