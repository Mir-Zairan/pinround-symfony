<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * An open round still takes screenshots, pins and verdicts; a closed one is a record.
 */
enum RoundState: string
{
    case Open = 'open';
    case Closed = 'closed';
}
