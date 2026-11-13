<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The open tickets one person or one label (for example "Customer") must act on, as one viewer may see them: a card of
 * the Whose turn view.
 */
final readonly class TurnGroup
{
    /**
     * @param UserId|null         $turnUserId         the person; null for a label group
     * @param string|null         $turnLabel          who the tickets wait on outside the team; null for a person
     * @param int                 $ticketCount        every ticket of the group the viewer may see, shown or not
     * @param \DateTimeImmutable  $oldestWaitingSince the earliest waiting_since of those tickets
     * @param list<WaitingTicket> $tickets            the oldest waits first, at most 5, or 100 for the opened group
     *
     * @throws \InvalidArgumentException when not exactly one of $turnUserId and $turnLabel is set
     */
    public function __construct(
        public ?UserId $turnUserId,
        public ?string $turnLabel,
        public int $ticketCount,
        public \DateTimeImmutable $oldestWaitingSince,
        public array $tickets,
    ) {
        if ((null === $turnUserId) === (null === $turnLabel)) {
            throw new \InvalidArgumentException('A whose-turn group is either a person\'s or a label\'s.');
        }
    }

    /**
     * The value that names this group as the opened one in HomeQueries::whoseTurn: the user id string, or the label.
     */
    public function groupKey(): string
    {
        return $this->turnUserId?->toString() ?? (string) $this->turnLabel;
    }
}
