<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The ticket TicketCommands::report made, as the toast after "Create ticket" needs it.
 */
final readonly class ReportedTicket
{
    /**
     * @param string       $key          for example SHOP-142
     * @param list<string> $checkReasons the CheckReason values it is checked for, in the order the preview names them
     * @param bool         $isTeamOnly   true when its words made it visible to the team and admins only
     */
    public function __construct(
        public string $key,
        public array $checkReasons,
        public bool $isTeamOnly,
    ) {
    }
}
