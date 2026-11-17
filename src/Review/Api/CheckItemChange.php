<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * What a change of a checklist item did, so the toast can say "Pin N marked fixed." and how many pings it cleared.
 */
final readonly class CheckItemChange
{
    /**
     * @param int    $pinNumber        the number of the item's pin on its Round 0 picture, from 1
     * @param string $state            ChecklistCommands::STATE_FIXED, STATE_OK or STATE_NOT_FIXED
     * @param int    $clearedPingCount what PingClearing::clearForActor returned for the change; 0 or more
     */
    public function __construct(
        public int $pinNumber,
        public string $state,
        public int $clearedPingCount,
    ) {
    }
}
