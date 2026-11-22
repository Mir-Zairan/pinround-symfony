<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A reviewer's verdict on one screen area of a review round, and the optional one of a general note on a shared
 * round (GeneralNote). None of them moves the ticket (phase 5 design notes).
 */
enum AreaVerdict: string
{
    case Pass = 'pass';
    case NeedsFix = 'needs_fix';
    case Question = 'question';
}
