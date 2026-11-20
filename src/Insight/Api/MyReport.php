<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One ticket the viewer reported, open or done, as My reports lists it: the ticket's own facts and, while it is
 * somebody's turn, its turn row.
 */
final readonly class MyReport
{
    /**
     * @param string                  $stepId               the uuid of the ticket's step
     * @param \DateTimeImmutable      $reportedAt           when the ticket was made, to the second
     * @param \DateTimeImmutable      $updatedAt            the ticket's last change, to the second
     * @param \DateTimeImmutable|null $closedAt             when its step's group became Done; null while it is open
     * @param string|null             $turnGroup            the five-group code of its step (todo, doing, check, wait)
     *                                                      from its turn row; null when it has none (done, or nobody's
     *                                                      turn)
     * @param string|null             $turnKind             the turn row's kind (answer, build, …); null with no turn row
     * @param bool                    $isQuestionToReporter whether an open question waits on the reporter's answer
     *                                                      (the turn is theirs, of kind answer)
     */
    public function __construct(
        public TicketId $ticketId,
        public string $key,
        public string $title,
        public string $stepId,
        public \DateTimeImmutable $reportedAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $closedAt,
        public ?string $turnGroup,
        public ?UserId $turnUserId,
        public ?string $turnLabel,
        public ?string $turnKind,
        public bool $isQuestionToReporter,
    ) {
    }

    public function isDone(): bool
    {
        return null !== $this->closedAt;
    }
}
