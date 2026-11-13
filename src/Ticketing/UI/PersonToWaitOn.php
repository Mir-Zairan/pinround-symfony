<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * One of the ticket's people a lead can make a Needs input move wait on.
 */
final readonly class PersonToWaitOn
{
    /**
     * @param string $userId    the person's user uuid, as the move's waiting-on field sends it
     * @param string $roleNames the person's roles on the ticket, joined by " · ", for example "Reporter · Assignee"
     */
    public function __construct(
        public string $userId,
        public string $firstName,
        public string $roleNames,
    ) {
    }
}
