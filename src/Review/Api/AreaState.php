<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The chip on a screen area's tab of the review round page: "Passed", "N pins", "Needs fix", "Question" or
 * "Not reviewed".
 */
enum AreaState: string
{
    case Passed = 'passed';
    case Pins = 'pins';
    case NeedsFix = 'needs_fix';
    case Question = 'question';
    case NotReviewed = 'not_reviewed';
}
