<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * The verdict with an optional note on one screenshot of a shared draft, as an Area holds one on a screen area of a
 * review round (ADR-0048, note of 2026-11-22). A screenshot has at most one; the next one saved takes its place. Not
 * ScreenshotVerdict, which is the draft owner's own look at a picture.
 */
final readonly class DraftScreenshotVerdict
{
    private function __construct(
        public RoundId $roundId,
        public ScreenshotId $screenshotId,
        public AreaVerdict $verdict,
        public ?string $note,
        public UserId $decidedBy,
        public \DateTimeImmutable $decidedAt,
    ) {
    }

    /**
     * @param string|null $note trimmed; a blank one is none
     *
     * @throws \DomainException          when there is no verdict (Area::VERDICT_MISSING)
     * @throws \InvalidArgumentException when the note is over Area::NOTE_MAX_LENGTH characters
     */
    public static function decide(RoundId $roundId, ScreenshotId $screenshotId, UserId $decidedBy, ?AreaVerdict $verdict, ?string $note, \DateTimeImmutable $decidedAt): self
    {
        if (null === $verdict) {
            throw new \DomainException(Area::VERDICT_MISSING);
        }

        return new self($roundId, $screenshotId, $verdict, Area::noteOf($note), $decidedBy, $decidedAt);
    }
}
