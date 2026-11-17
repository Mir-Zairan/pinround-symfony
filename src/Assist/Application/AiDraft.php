<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Ticketing\Api\ListFilter;

/**
 * A report the person is still typing, for the intake task: it has no ticket yet. The sizes are a backstop for the
 * prompt; the page that takes the report refuses a longer one with a sentence first.
 */
final readonly class AiDraft
{
    public const int MAX_TITLE_CHARACTERS = 200;
    public const int MAX_DETAIL_CHARACTERS = 50_000;
    public const int MAX_PIN_NOTES = 100;
    public const int MAX_PIN_NOTE_CHARACTERS = 1_000;

    /**
     * @param string       $projectId the project's canonical lowercase uuid, as the caller may not build Ticketing's
     *                                ProjectId
     * @param list<string> $pinNotes  the notes of the pins on its screenshot, in pin order
     *
     * @throws \InvalidArgumentException when the project id is not a canonical uuid or a text is over its size
     */
    public function __construct(
        public string $projectId,
        public string $title,
        public string $detail,
        public array $pinNotes = [],
    ) {
        if (!ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('A draft report\'s project id must be a canonical lowercase uuid.');
        }
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE_CHARACTERS) {
            throw new \InvalidArgumentException(\sprintf('A draft report\'s title has at most %d characters.', self::MAX_TITLE_CHARACTERS));
        }
        if (mb_strlen($detail, 'UTF-8') > self::MAX_DETAIL_CHARACTERS) {
            throw new \InvalidArgumentException(\sprintf('A draft report\'s detail has at most %d characters.', self::MAX_DETAIL_CHARACTERS));
        }
        if (\count($pinNotes) > self::MAX_PIN_NOTES) {
            throw new \InvalidArgumentException(\sprintf('A draft report has at most %d pin notes.', self::MAX_PIN_NOTES));
        }
        foreach ($pinNotes as $pinNote) {
            if (mb_strlen($pinNote, 'UTF-8') > self::MAX_PIN_NOTE_CHARACTERS) {
                throw new \InvalidArgumentException(\sprintf('A draft report\'s pin note has at most %d characters.', self::MAX_PIN_NOTE_CHARACTERS));
            }
        }
    }

    /**
     * The detail and the pin notes as one text for the prompt, each note on its own line under its pin number; null when
     * all of them are blank.
     */
    public function promptText(): ?string
    {
        $lines = '' === trim($this->detail) ? [] : [$this->detail];
        foreach ($this->pinNotes as $index => $pinNote) {
            if ('' !== trim($pinNote)) {
                $lines[] = \sprintf('Pin %d: %s', $index + 1, $pinNote);
            }
        }

        return [] === $lines ? null : implode("\n", $lines);
    }
}
