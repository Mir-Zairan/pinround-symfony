<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * A report the person is still typing on the report page, for "Check with AI". It is taken as typed: IntakeAssist
 * refuses one that is too long or not UTF-8 with a sentence, before anything runs.
 */
final readonly class DraftReport
{
    /**
     * @param string       $projectId the project's canonical lowercase uuid
     * @param list<string> $pinNotes  the notes of the pins on its screenshot, in pin order
     */
    public function __construct(
        public string $projectId,
        public string $title,
        public string $detail,
        public array $pinNotes = [],
    ) {
    }
}
