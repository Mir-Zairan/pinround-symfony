<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * A report as the reporter has typed it so far, before it becomes a ticket: what the intake checks read.
 */
final readonly class DraftReport
{
    /**
     * @param list<string> $pinNotes the notes of the pins on its screenshot, in pin order
     */
    public function __construct(
        public string $title,
        public string $detail,
        public array $pinNotes,
    ) {
    }
}
