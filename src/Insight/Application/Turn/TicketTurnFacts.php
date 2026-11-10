<?php

declare(strict_types=1);

namespace App\Insight\Application\Turn;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;

/**
 * Everything the turn row of one ticket is made from: the ticket as the turn rules see it, its team, and what the rules
 * do not need but the row copies or scores. The people columns come from $ticket.
 */
final readonly class TicketTurnFacts
{
    private const array VISIBILITIES = ['all', 'team'];

    /**
     * @param string      $projectId      the uuid of the ticket's project
     * @param string      $visibility     all, or team for a restricted ticket (data-model.md, "Ticket visibility predicate")
     * @param string|null $currentRelease the organization's current release; null when it has none
     * @param bool        $isRiskCheck    true while the ticket's check reasons include "risk"
     *
     * @throws \InvalidArgumentException when the ticket has no reporter or the visibility is not all or team
     */
    public function __construct(
        public TicketId $ticketId,
        public string $projectId,
        public TicketState $ticket,
        public TeamFacts $team,
        public string $visibility,
        public ?string $currentRelease = null,
        public bool $isRiskCheck = false,
    ) {
        if (null === $ticket->reporterId) {
            throw new \InvalidArgumentException('The turn facts of a ticket need its reporter.');
        }
        if (!\in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('A ticket visibility must be all or team.');
        }
    }

    public function reporterId(): UserId
    {
        return $this->ticket->reporterId ?? throw new \LogicException('The constructor refuses facts without a reporter.');
    }
}
