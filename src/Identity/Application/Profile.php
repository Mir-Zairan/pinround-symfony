<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * What the profile page shows of a user. The theme is a Theme value ("system", "light" or "dark").
 */
final readonly class Profile
{
    public function __construct(
        public string $name,
        public string $timeZone,
        public string $theme,
    ) {
    }
}
