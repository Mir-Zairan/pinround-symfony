<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One open ticket whose turn is the viewer's, as Home's "Needs you now" card shows it: what it is, why it ranks where it
 * does and the people its task names.
 */
final readonly class NeedsYouTicket
{
    /**
     * @param string      $key        the ticket's key, for example SHOP-12
     * @param string      $kind       the turn kind (answer, check, review, test, assign, input, decide, build), a
     *                                NeedsYouKind value
     * @param string      $stepId     the ticket's current step, a uuid
     * @param string      $priority   blocker, critical, major, normal or minor
     * @param UserId|null $askerId    who asked the open question; null while none is open
     * @param UserId|null $assigneeId the ticket's assignee; null when nobody is assigned
     */
    public function __construct(
        public TicketId $id,
        public string $key,
        public string $title,
        public string $kind,
        public \DateTimeImmutable $waitingSince,
        public string $stepId,
        public string $priority,
        public ?UserId $askerId,
        public ?UserId $assigneeId,
    ) {
    }
}
