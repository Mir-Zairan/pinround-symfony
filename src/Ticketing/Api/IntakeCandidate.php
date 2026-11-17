<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * An open ticket a draft report may duplicate, as the intake check sends it to the AI: its key and title only.
 */
final readonly class IntakeCandidate
{
    /**
     * @param string $key for example "SHOP-12"
     */
    public function __construct(
        public string $key,
        public string $title,
    ) {
    }
}
