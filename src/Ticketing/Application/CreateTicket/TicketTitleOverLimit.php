<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

/**
 * The trimmed title has more than 200 characters. In the Application, not the Domain, so the ticket form may catch it
 * and show its message.
 */
final class TicketTitleOverLimit extends \InvalidArgumentException
{
}
