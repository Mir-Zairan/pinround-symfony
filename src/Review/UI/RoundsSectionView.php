<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * What the ticket page's Rounds section shows: its cards in order, and how many older rounds were left out.
 */
final readonly class RoundsSectionView
{
    /**
     * @param list<RoundCard> $cards
     */
    public function __construct(public array $cards, public int $olderRoundsNotShown)
    {
        if ($olderRoundsNotShown < 0) {
            throw new \InvalidArgumentException('A count of rounds left out is not below 0.');
        }
    }

    /**
     * Whether a card is the viewer's own unshared draft, so "Start a draft round" is not offered beside it.
     */
    public function hasPrivateDraft(): bool
    {
        return array_any($this->cards, static fn ($card) => $card->isPrivateDraft);
    }

    /**
     * "3 older rounds are not shown." or "1 older round is not shown."; null when none was left out.
     */
    public function olderRoundsNote(): ?string
    {
        if (0 === $this->olderRoundsNotShown) {
            return null;
        }

        return 1 === $this->olderRoundsNotShown
            ? '1 older round is not shown.'
            : \sprintf('%d older rounds are not shown.', $this->olderRoundsNotShown);
    }
}
