<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\TicketId;

/**
 * What a verdict or a general note needs of the round it is given in, as Areas::lockRound and GeneralNotes::lockRound
 * read it.
 */
final readonly class ReviewedRound
{
    public const string TAKES_NO_VERDICTS = 'Only a shared review round takes verdicts.';
    public const string CLOSED = 'This round is closed, so its verdicts no longer change.';
    public const string TAKES_NO_GENERAL_NOTES = 'Only a shared round takes general notes.';
    public const string CLOSED_FOR_GENERAL_NOTES = 'This round is closed, so it takes no more notes.';

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

    /**
     * A shared report, review round or draft takes general notes while it is open (ADR-0048, note of 2026-11-22); the
     * checklist, an unshared or a deleted draft never does.
     *
     * @throws \DomainException TAKES_NO_GENERAL_NOTES or CLOSED_FOR_GENERAL_NOTES
     */
    public function requireOpenForGeneralNotes(): void
    {
        if ($this->isDeleted || !$this->isShared || RoundKind::Checklist === $this->kind) {
            throw new \DomainException(self::TAKES_NO_GENERAL_NOTES);
        }
        if (RoundState::Open !== $this->state) {
            throw new \DomainException(self::CLOSED_FOR_GENERAL_NOTES);
        }
    }
}
