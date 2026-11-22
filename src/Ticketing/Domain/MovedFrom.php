<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The fields a move changes, as they were just before it: what Undo puts back. The assignee is not among them, as a
 * move never changes it (a give, which does, has no Undo).
 */
final readonly class MovedFrom
{
    /**
     * @param string      $stepId          the wf_step id the move left
     * @param string|null $previousStepId  the ticket's previous step before the move
     * @param string|null $waitingOnUserId set only when the move left Needs input
     * @param string|null $waitingOnLabel  set only when the move left Needs input
     */
    public function __construct(
        public string $stepId,
        public ?string $previousStepId,
        public ?string $waitingOnUserId,
        public ?string $waitingOnLabel,
        public ?string $reviewerId,
        public ?string $testerId,
        public ?\DateTimeImmutable $closedAt,
    ) {
    }
}
