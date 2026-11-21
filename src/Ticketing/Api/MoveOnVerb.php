<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * One move-on verb as the ticket page's Your next step box shows it, for example "Pass it".
 */
final readonly class MoveOnVerb
{
    /**
     * @param string $verb the move route's verb, for example pass_it
     */
    public function __construct(
        public string $verb,
        public string $label,
        public bool $isPrimary,
    ) {
    }
}
