<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * How far the checking of a ticket's pins is: of its current checklist, how many pins are checked OK.
 */
final readonly class PinProgress
{
    /**
     * @param int<0, max> $okCount  the pins checked OK, at most $pinCount
     * @param int<1, max> $pinCount the pins the checklist has
     */
    public function __construct(public int $okCount, public int $pinCount)
    {
    }
}
