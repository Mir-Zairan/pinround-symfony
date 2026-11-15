<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A reviewer's verdict on one pin of a review round.
 */
enum PinVerdict: string
{
    case Fixed = 'fixed';
    case StillWrong = 'still_wrong';
}
