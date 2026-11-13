<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * Someone saved the ticket after the caller read it, so the change was not saved; the caller reloads and asks again.
 */
final class TicketChangedMeanwhile extends \DomainException
{
}
