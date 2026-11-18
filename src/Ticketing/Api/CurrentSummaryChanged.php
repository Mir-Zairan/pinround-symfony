<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Ticketing did not store a summary a person chose (TicketSummaryCommands::storeChosenLaptopSummary): the ticket's
 * current summary is no longer the one they saw, or the ticket is gone. Nothing was stored.
 */
final class CurrentSummaryChanged extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The ticket\'s current summary changed since the page was read.');
    }
}
