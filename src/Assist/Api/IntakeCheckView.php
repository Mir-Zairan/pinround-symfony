<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * One "Check with AI" for the person who asked, for the page that polls it: queued while the AI reads the draft,
 * done with its suggestion, or failed with the line to show. It never carries the draft's text.
 */
final readonly class IntakeCheckView
{
    public const string QUEUED = 'queued';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    /**
     * @param string                $status     QUEUED, DONE or FAILED
     * @param string|null           $sentence   the line to show when FAILED; null otherwise
     * @param IntakeSuggestion|null $suggestion the suggestion when DONE; null otherwise
     */
    private function __construct(
        public string $status,
        public ?string $sentence,
        public ?IntakeSuggestion $suggestion,
    ) {
    }

    public static function queued(): self
    {
        return new self(self::QUEUED, null, null);
    }

    public static function done(IntakeSuggestion $suggestion): self
    {
        return new self(self::DONE, null, $suggestion);
    }

    /**
     * @param string $sentence the line to show, never the reason the provider or the check gave
     *
     * @throws \InvalidArgumentException when the sentence is blank
     */
    public static function failed(string $sentence): self
    {
        if ('' === trim($sentence)) {
            throw new \InvalidArgumentException('A failed intake check says why in a sentence.');
        }

        return new self(self::FAILED, $sentence, null);
    }

    public function isQueued(): bool
    {
        return self::QUEUED === $this->status;
    }
}
