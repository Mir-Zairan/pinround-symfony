<?php

declare(strict_types=1);

namespace App\Review\Application\Checklist;

/**
 * The picture a check item's pin is on, which tells apart the pins of one round that share a number: pin numbers start
 * at 1 on each picture.
 */
final readonly class CheckItemPicture
{
    /**
     * @param int         $position the picture's place in its round, from 0
     * @param string|null $title    the picture's title; null when it has none
     */
    public function __construct(
        public int $position,
        public ?string $title,
    ) {
    }
}
