<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

/**
 * Nothing is left of the title after trimming. In the Application, not the Domain, so the ticket form may catch it and
 * show its message.
 */
final class TicketTitleMissing extends \InvalidArgumentException
{
}
