<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

use App\Review\Domain\Screenshot;

/**
 * A picture in a notebook, by its Media id, with an optional title and summary and its pins, under the same caps as a
 * round's screenshot (Screenshot).
 */
final readonly class NotebookScreenshot
{
    // Version nibble 7 and variant bits 10xx, as Media's ids are; \z, because $ would also accept a trailing newline.
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * @param list<NotebookPin> $pins
     */
    private function __construct(
        private string $mediaId,
        private ?string $title,
        private ?string $summary,
        private array $pins,
    ) {
    }

    /**
     * The title and summary are trimmed, and a blank one is none. Lengths count characters, as PostgreSQL's
     * char_length does.
     *
     * @param string            $mediaId the uuid of the Media object that holds the picture, in any case
     * @param list<NotebookPin> $pins    numbered 1, 2, … in this order
     *
     * @throws NotebookRefused when the media id is not a UUIDv7, the title is longer than Screenshot::TITLE_MAX_LENGTH
     *                         or the summary than Screenshot::SUMMARY_MAX_LENGTH, or there are more than
     *                         Screenshot::MAX_PINS pins
     */
    public static function of(string $mediaId, ?string $title, ?string $summary, array $pins): self
    {
        $lowercaseMediaId = strtolower($mediaId);
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $lowercaseMediaId)) {
            throw NotebookRefused::because(NotebookRefused::PICTURE_NOT_UPLOADED);
        }
        $trimmedTitle = self::blankToNull($title);
        if (null !== $trimmedTitle && mb_strlen($trimmedTitle) > Screenshot::TITLE_MAX_LENGTH) {
            throw NotebookRefused::because(NotebookRefused::PICTURE_TITLE_TOO_LONG);
        }
        $trimmedSummary = self::blankToNull($summary);
        if (null !== $trimmedSummary && mb_strlen($trimmedSummary) > Screenshot::SUMMARY_MAX_LENGTH) {
            throw NotebookRefused::because(NotebookRefused::PICTURE_SUMMARY_TOO_LONG);
        }
        if (\count($pins) > Screenshot::MAX_PINS) {
            throw NotebookRefused::because(NotebookRefused::TOO_MANY_PINS);
        }

        return new self($lowercaseMediaId, $trimmedTitle, $trimmedSummary, $pins);
    }

    public function mediaId(): string
    {
        return $this->mediaId;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    /**
     * @return list<NotebookPin> numbered 1, 2, … in this order
     */
    public function pins(): array
    {
        return $this->pins;
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = null === $text ? '' : trim($text);

        return '' === $trimmed ? null : $trimmed;
    }
}
