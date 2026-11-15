<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The ticket has a round with that number already, or the owner an open draft on it
 * (rvw_round_ticket_id_number_key, rvw_round_ticket_id_owner_id_open_draft_key); nothing of the round was written.
 */
final class RoundSlotTaken extends \DomainException
{
}
