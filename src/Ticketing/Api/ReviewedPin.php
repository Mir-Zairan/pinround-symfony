<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * A pin of an earlier round as a review round compares it, for the ticket's history.
 */
final readonly class ReviewedPin
{
    /**
     * @param int    $number   the pin's number on its picture, from 1
     * @param string $areaName the name of the review round's area that compares the pin's picture
     */
    public function __construct(public int $number, public string $areaName)
    {
    }
}
