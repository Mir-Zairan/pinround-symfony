<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * The first open tickets whose turn is the viewer's, as Home's "Needs you now" lists them, and how many wait on them.
 */
final readonly class ViewersTurns
{
    /**
     * @param list<WaitingTicket> $tickets     the oldest waits first, then the lowest ticket id
     * @param int                 $ticketCount every open ticket whose turn is the viewer's and that the viewer may see,
     *                                         shown or not
     */
    public function __construct(public array $tickets, public int $ticketCount)
    {
    }
}
