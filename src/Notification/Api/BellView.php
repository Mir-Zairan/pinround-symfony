<?php

declare(strict_types=1);

namespace App\Notification\Api;

/**
 * What the bell shows a person: how many notifications are open, and their groups, the largest first and, among
 * groups of one size, the one with the newest notification first.
 */
final readonly class BellView
{
    /**
     * @param int             $openCount every open notification, also those in groups past the cap
     * @param list<BellGroup> $groups    the first 50
     */
    public function __construct(public int $openCount, public array $groups)
    {
    }
}
