<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The organization has no ticket with that key, or the key is malformed; the caller answers it as not found (404).
 */
final class TicketNotFound extends \DomainException
{
}
