<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The bar across a ticket page while its ticket is in the person's queue (home-work.md, Queue bar): "{label} · 2 of 4",
 * Previous (disabled on the first), Next ticket or, on the last, Finish, and Stop. Finish and Stop post a form with
 * csrfToken; it shows no ticket's title.
 */
#[AsTwigComponent]
final class QueueBar
{
    // Props are public and mutable because the component system sets them after construction.
    public string $label = '';

    /** From 1. */
    public int $place = 0;

    public int $count = 0;

    /** Null on the first ticket. */
    public ?string $previousUrl = null;

    /** Null on the last ticket, which shows Finish instead. */
    public ?string $nextUrl = null;

    public string $finishUrl = '';

    public string $stopUrl = '';

    public string $csrfToken = '';

    #[PostMount]
    public function assertIsAPlaceInTheQueue(): void
    {
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('A QueueBar needs a non-empty label.');
        }
        if ($this->place < 1 || $this->place > $this->count) {
            throw new \InvalidArgumentException('A QueueBar\'s place is from 1 to its count.');
        }
        if ('' === $this->finishUrl || '' === $this->stopUrl || '' === $this->csrfToken) {
            throw new \InvalidArgumentException('A QueueBar needs its Finish and Stop URLs and a CSRF token.');
        }
    }
}
