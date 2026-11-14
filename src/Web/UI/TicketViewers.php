<?php

declare(strict_types=1);

namespace App\Web\UI;

use App\Insight\Api\TicketViewer;
use App\Ticketing\Api\TicketVisibility;

/**
 * Hands a viewer's Ticketing visibility to an Insight read, which may not name TicketVisibility, field by field so the
 * rule stays Ticketing's.
 */
final class TicketViewers
{
    public static function of(TicketVisibility $visibility): TicketViewer
    {
        return new TicketViewer($visibility->organizationId(), $visibility->viewerId(), $visibility->teamIds(), $visibility->isAdmin(), $visibility->isTriager());
    }
}
