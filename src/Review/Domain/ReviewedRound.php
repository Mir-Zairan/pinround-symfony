<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\TicketId;

/**
 * What a verdict needs of the round it is given in, as Areas::lockRound reads it.
 */
final readonly class ReviewedRound
{
    public const string TAKES_NO_VERDICTS = 'Only a shared review round takes verdicts.';
    public const string CLOSED = 'This round is closed, so its verdicts no longer change.';

    public function __construct(
        public RoundId $id,
        public TicketId $ticketId,
        public RoundKind $kind,
        public bool $isShared,
        public RoundState $state,
        public bool $isDeleted,
    ) {
    }

    /**
     * A shared review round or shared draft takes verdicts while it is open; a report, a checklist, an unshared or
     * deleted draft never does.
     *
     * @throws \DomainException TAKES_NO_VERDICTS or CLOSED
     */
    public function requireOpenForVerdicts(): void
    {
        if ($this->isDeleted || !$this->isShared || !\in_array($this->kind, [RoundKind::Review, RoundKind::Draft], true)) {
            throw new \DomainException(self::TAKES_NO_VERDICTS);
        }
        if (RoundState::Open !== $this->state) {
            throw new \DomainException(self::CLOSED);
        }
    }
}
