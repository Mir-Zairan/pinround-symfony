<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * One screen area of a review round (FR-RND-05), for example "Per product": the round's "after" picture of that place,
 * the earlier round's picture of it ("before", none when there is no earlier one), and the reviewer's verdict with an
 * optional note. The pins checked on it are the earlier round's pins on the "before" picture.
 */
final readonly class Area
{
    /** The same cap as rvw_area_name_check. */
    public const int NAME_MAX_LENGTH = 80;

    /** The same cap as rvw_area_note_check. */
    public const int NOTE_MAX_LENGTH = 1000;

    /** The prototype's toast when "Save verdict" is clicked with no chip chosen. */
    public const string VERDICT_MISSING = 'Pick Pass, Needs fix or Question first.';

    private function __construct(
        private AreaId $id,
        private RoundId $roundId,
        private string $name,
        private int $position,
        private ?ScreenshotId $beforeScreenshotId,
        private ScreenshotId $afterScreenshotId,
        private ?AreaVerdict $verdict,
        private ?string $note,
        private ?UserId $decidedBy,
        private ?\DateTimeImmutable $decidedAt,
    ) {
    }

    /**
     * A stored area as Areas reads it back.
     *
     * @throws \InvalidArgumentException when the name is blank or over NAME_MAX_LENGTH characters, the position is below
     *                                   0, both pictures are one, or the verdict, who decided and when do not come together
     */
    public static function restored(
        AreaId $id,
        RoundId $roundId,
        string $name,
        int $position,
        ?ScreenshotId $beforeScreenshotId,
        ScreenshotId $afterScreenshotId,
        ?AreaVerdict $verdict,
        ?string $note,
        ?UserId $decidedBy,
        ?\DateTimeImmutable $decidedAt,
    ): self {
        $trimmedName = trim($name);
        if ('' === $trimmedName || mb_strlen($trimmedName) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('An area name holds 1 to %d characters.', self::NAME_MAX_LENGTH));
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('An area position starts at 0.');
        }
        if (null !== $beforeScreenshotId && $beforeScreenshotId->equals($afterScreenshotId)) {
            throw new \InvalidArgumentException('An area compares two different pictures.');
        }
        if ((null === $verdict) !== (null === $decidedBy) || (null === $verdict) !== (null === $decidedAt)) {
            throw new \InvalidArgumentException('An area has who decided and when exactly when it has a verdict.');
        }

        return new self($id, $roundId, $trimmedName, $position, $beforeScreenshotId, $afterScreenshotId, $verdict, self::noteOf($note), $decidedBy, $decidedAt);
    }

    /**
     * The area with $decidedBy's verdict and note in place of any earlier one; this area stays as it was.
     *
     * @param string|null $note trimmed; a blank one is none
     *
     * @throws \DomainException          when there is no verdict (VERDICT_MISSING)
     * @throws \InvalidArgumentException when the note is over NOTE_MAX_LENGTH characters
     */
    public function decide(UserId $decidedBy, ?AreaVerdict $verdict, ?string $note, \DateTimeImmutable $decidedAt): self
    {
        if (null === $verdict) {
            throw new \DomainException(self::VERDICT_MISSING);
        }

        return new self($this->id, $this->roundId, $this->name, $this->position, $this->beforeScreenshotId, $this->afterScreenshotId, $verdict, self::noteOf($note), $decidedBy, $decidedAt);
    }

    public function id(): AreaId
    {
        return $this->id;
    }

    public function roundId(): RoundId
    {
        return $this->roundId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * Null when the round has no earlier picture of this place.
     */
    public function beforeScreenshotId(): ?ScreenshotId
    {
        return $this->beforeScreenshotId;
    }

    public function afterScreenshotId(): ScreenshotId
    {
        return $this->afterScreenshotId;
    }

    public function verdict(): ?AreaVerdict
    {
        return $this->verdict;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function decidedBy(): ?UserId
    {
        return $this->decidedBy;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /**
     * @throws \InvalidArgumentException when the trimmed note is over NOTE_MAX_LENGTH characters
     */
    private static function noteOf(?string $note): ?string
    {
        $trimmedNote = trim($note ?? '');
        if (mb_strlen($trimmedNote) > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A note holds at most %s characters.', number_format(self::NOTE_MAX_LENGTH)));
        }

        return '' === $trimmedNote ? null : $trimmedNote;
    }
}
