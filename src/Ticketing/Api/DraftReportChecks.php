<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The intake checks of a report the reporter is still typing (FR-INT-09), for a module that must decide what to do with
 * its text before it becomes a ticket, such as whether it may go to cloud AI. It reads nothing from the database.
 */
interface DraftReportChecks
{
    /**
     * True when the security word list matches the title, the detail or any pin note. A text that is not valid UTF-8
     * cannot be checked, so it counts as sensitive: the check fails closed.
     *
     * @param list<string> $pinNotes the notes of the pins on its screenshot, in pin order
     */
    public function isSecuritySensitive(string $title, string $detail, array $pinNotes): bool;
}
