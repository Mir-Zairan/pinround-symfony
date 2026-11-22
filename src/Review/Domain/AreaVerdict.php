<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A reviewer's verdict on one screen area of a review round, or on one screenshot of a shared draft
 * (DraftScreenshotVerdict). None of them moves the ticket (phase 5 design notes).
 */
enum AreaVerdict: string
{
    case Pass = 'pass';
    case NeedsFix = 'needs_fix';
    case Question = 'question';
}
