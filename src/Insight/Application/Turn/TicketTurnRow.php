<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NeedsYouKind;

/**
 * One row of ins_ticket_turn, short of organization_id and waiting_since, which the writer adds. The copied columns
 * (team, project, group, visibility, people) are read from $facts.
 */
final readonly class TicketTurnRow
{
    /**
     * @param UserId|null $turnUserId  the person whose turn it is; null when a label holds the turn
     * @param string|null $turnLabel   who the ticket waits on outside the team, for example "Customer"; set only
     *                                 when $turnUserId is null
     * @param UserId|null $checkUserId the person who takes the quick look, while the ticket needs one
     *
     * @throws \InvalidArgumentException when not exactly one of $turnUserId and $turnLabel is set, as the table's
     *                                   ins_ticket_turn_turn_check demands
     */
    public function __construct(
        public TicketTurnFacts $facts,
        public ?UserId $turnUserId,
        public ?string $turnLabel,
        public NeedsYouKind $kind,
        public int $baseScore,
        public ?UserId $checkUserId,
    ) {
        if ((null === $turnUserId) === (null === $turnLabel)) {
            throw new \InvalidArgumentException('A turn row names a person or a label, never both and never neither.');
        }
    }
}
