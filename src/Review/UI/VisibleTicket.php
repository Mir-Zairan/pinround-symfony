<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Organization\Api\ActorContext;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketVisibility;

/**
 * A ticket the logged-in person may see, with who they are and what they may see of it.
 */
final readonly class VisibleTicket
{
    public function __construct(
        public ActorContext $actor,
        public TicketVisibility $visibility,
        public TicketPageView $ticket,
    ) {
    }
}
