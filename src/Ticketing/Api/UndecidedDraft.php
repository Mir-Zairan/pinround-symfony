<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The mover's own draft round that a move asks about: private, open, not decided yet and with at least one screenshot.
 */
final readonly class UndecidedDraft
{
    /**
     * @param int         $screenshotCount         from 1
     * @param int         $roundNumber             the number the draft gets when it is shared now, from 1
     * @param int|null    $openRoundNumber         the shared round that stays open after this move and so blocks sharing
     *                                             the draft in it (ADR-0048); null when the draft may be shared
     * @param UserId|null $openRoundReadyForUserId whose verdict that round waits for, when Review knows it: the assignee
     *                                             for their findings round; null for any other round (whoever's turn it
     *                                             is) and when sharing is not blocked
     *
     * @throws \InvalidArgumentException when a person is named for no open round
     */
    public function __construct(
        public string $roundId,
        public int $screenshotCount,
        public int $roundNumber,
        public ?int $openRoundNumber = null,
        public ?UserId $openRoundReadyForUserId = null,
    ) {
        if (null === $openRoundNumber && null !== $openRoundReadyForUserId) {
            throw new \InvalidArgumentException('Only an open round that blocks the share waits for a person.');
        }
    }

    /**
     * Sharing waits for round openRoundNumber, so the move offers only "Keep private and move".
     */
    public function isSharingBlocked(): bool
    {
        return null !== $this->openRoundNumber;
    }
}
