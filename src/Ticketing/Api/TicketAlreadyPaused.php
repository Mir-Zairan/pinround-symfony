<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Another question already pauses the ticket, so it cannot take a second one (FR-CNV-08); nothing was saved.
 */
final class TicketAlreadyPaused extends \DomainException
{
}
