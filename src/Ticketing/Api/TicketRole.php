<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What a person does on a ticket, as the ticket page's People section lists it (FR-TKT-11). The values are the codes
 * other modules show, such as get_ticket's roles.
 */
enum TicketRole: string
{
    case Assignee = 'assignee';
    case Reporter = 'reporter';
    case Reviewer = 'reviewer';
    case Tester = 'tester';
}
