<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Who placed a pin: a person, or the AI as a suggestion a person keeps or dismisses (ADR-0020).
 */
enum PinSource: string
{
    case Human = 'human';
    case Ai = 'ai';
}
