<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

/**
 * The description has more than 50,000 characters. In the Application, not the Domain, so the ticket form may catch it
 * and show its message.
 */
final class TicketDescriptionOverLimit extends \InvalidArgumentException
{
}
