<?php

declare(strict_types=1);

namespace App\Review\UI\Checklist;

use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistPermission;
use App\Review\Application\Checklist\RoundChecklist;
use App\Review\Application\Checklist\RoundChecklistQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\TicketTurnQueries;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Builds the checklist cards of a ticket's rounds for the logged-in viewer in two queries, whatever the number of rounds
 * and items: the items, and the ticket's facts, which CheckItemVoter then decides ChecklistPermission MARK_FIXED and
 * CHECK on without reading them again. Both are decided once for the ticket, on its first item, as the voter decides
 * them on the item's ticket alone, which every item of every card shares.
 */
final readonly class ChecklistCards
{
    public function __construct(
        private RoundChecklistQueries $roundChecklistQueries,
        private TicketTurnQueries $ticketTurnQueries,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization; the caller must have been granted
     * TicketPermission VIEW on the ticket first, and pass rounds the viewer sees.
     *
     * @param list<string> $roundIds lowercase ids of the ticket's checklist round and shared drafts
     *
     * @return array<string, ChecklistCard> by round id; a round without items (a self-check round) has none
     *
     * @throws \InvalidArgumentException when a round id is malformed, before any query
     */
    public function forRounds(OrganizationId $organizationId, TicketId $ticketId, array $roundIds): array
    {
        $roundChecklists = $this->roundChecklistQueries->forRounds($ticketId, $roundIds);
        $firstRoundChecklist = reset($roundChecklists);
        if (false === $firstRoundChecklist) {
            return [];
        }
        $firstChecklist = $firstRoundChecklist->checklist;
        $firstItem = $firstChecklist->items[0];
        $itemFacts = new CheckItemAccessFacts($organizationId, $firstItem->itemId, $firstChecklist->roundId, $ticketId, $firstItem->pinNumber, $this->ticketOf($ticketId));
        $mayMarkFixed = $this->authorizationChecker->isGranted(ChecklistPermission::MARK_FIXED, $itemFacts);
        $mayCheck = $this->authorizationChecker->isGranted(ChecklistPermission::CHECK, $itemFacts);

        return array_map(static fn (RoundChecklist $roundChecklist): ChecklistCard => ChecklistCard::of($roundChecklist, $mayMarkFixed, $mayCheck), $roundChecklists);
    }

    /**
     * @return TicketTurnSnapshot|null null when the facts cannot be read, so the voter reads them itself and denies
     */
    private function ticketOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        try {
            return $this->ticketTurnQueries->factsOf($ticketId);
        } catch (\LogicException) {
            return null;
        }
    }
}
