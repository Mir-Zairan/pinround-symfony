<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * "General notes on this round" on a shared round's page (ADR-0048, note of 2026-11-22): its notes, newest first, and
 * the form to add one for whoever RoundPermission ADD_GENERAL_NOTE grants while the round is open.
 */
final readonly class GeneralNotesSection
{
    /**
     * @param list<GeneralNoteRow> $notes      newest first
     * @param bool                 $mayAddNote whether the form shows: granted, and the round is open
     */
    public function __construct(
        public array $notes,
        public bool $mayAddNote,
    ) {
    }
}
