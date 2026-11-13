<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The ticket's row has a newer version than the ticket being saved, as another request saved it after this one read it.
 */
final class TicketVersionConflict extends \RuntimeException
{
}
