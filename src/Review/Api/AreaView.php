<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One screen area of a review round for its page: the earlier picture of the place ("before") and the round's own
 * ("after"), each with the pins that count, and the reviewer's verdict on the area.
 */
final readonly class AreaView
{
    /**
     * @param int                      $position          from 0, its tab's place in the round
     * @param RoundScreenshotView|null $before            the previous round's "after" picture or Round 0's report picture;
     *                                                    null when the round has no earlier picture of this place
     * @param int|null                 $beforeRoundNumber the number of the round $before belongs to; null exactly when $before is
     * @param string|null              $verdict           pass, needs_fix, question, or null before a reviewer decides
     * @param string|null              $note              the reviewer's note with the verdict; null when they wrote none
     * @param UserId|null              $decidedBy         null exactly when $verdict is
     * @param \DateTimeImmutable|null  $decidedAt         null exactly when $verdict is
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $position,
        public ?RoundScreenshotView $before,
        public ?int $beforeRoundNumber,
        public RoundScreenshotView $after,
        public ?string $verdict,
        public ?string $note,
        public ?UserId $decidedBy,
        public ?\DateTimeImmutable $decidedAt,
    ) {
    }

    /**
     * The earlier round's pins to check here: those on the "before" picture.
     */
    public function pinCount(): int
    {
        return null === $this->before ? 0 : \count($this->before->pins);
    }

    /**
     * Passed once the verdict is pass; otherwise "N pins" while the "before" picture has pins to check; with none, the
     * needs fix or question verdict given, else Not reviewed. An area with a verdict is never Not reviewed.
     *
     * @throws \UnexpectedValueException for a verdict that is none of VerdictCommands' AREA_ values
     */
    public function state(): AreaState
    {
        $withNoPins = match ($this->verdict) {
            VerdictCommands::AREA_PASS => AreaState::Passed,
            VerdictCommands::AREA_NEEDS_FIX => AreaState::NeedsFix,
            VerdictCommands::AREA_QUESTION => AreaState::Question,
            null => AreaState::NotReviewed,
            default => throw new \UnexpectedValueException(\sprintf('The area has the unknown verdict "%s".', $this->verdict)),
        };

        return AreaState::Passed !== $withNoPins && $this->pinCount() > 0 ? AreaState::Pins : $withNoPins;
    }
}
