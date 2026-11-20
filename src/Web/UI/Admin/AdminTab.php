<?php

declare(strict_types=1);

namespace App\Web\UI\Admin;

/**
 * One tab of the admin rail, as AdminTabRail gives it to the person who may open it.
 */
final readonly class AdminTab
{
    public function __construct(
        public string $label,
        public string $url,
        public bool $isCurrent,
    ) {
    }
}
