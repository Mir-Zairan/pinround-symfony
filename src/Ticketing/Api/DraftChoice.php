<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * What a move does with the mover's own undecided draft round on the ticket (ADR-0017, hand-off): "Share and move" or
 * "Keep private and move". The values are the ones the move form posts.
 */
enum DraftChoice: string
{
    case Share = 'share';
    case Keep = 'keep';
}
