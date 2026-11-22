<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * Reads the general notes on a round (GeneralNoteCommands). Reads under the caller's open transaction with the
 * organization as tenant; the caller must have been granted RoundPermission VIEW on the round.
 */
interface GeneralNoteQueries
{
    /**
     * One query, newest first (then by id, newest first), at most GeneralNoteCommands::MAX_PER_ROUND.
     *
     * @param string $roundId the round's id; a malformed one has no notes
     *
     * @return list<GeneralNoteView>
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function notesOf(string $roundId): array;
}
