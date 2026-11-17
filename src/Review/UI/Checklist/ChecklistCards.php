<?php

declare(strict_types=1);

namespace App\Review\UI\Checklist;

use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistPermission;
use App\Review\Api\ChecklistQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\Ticketing\Api\TicketTurnQueries;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Builds a ticket's checklist card for the logged-in viewer in two queries, whatever its number of items: the items, and
 * the ticket's facts, which CheckItemVoter then decides ChecklistPermission MARK_FIXED and CHECK on without reading them
 * again. Both are decided once, on the first item, as the voter decides them on the item's ticket alone, which every
 * item of the card shares.
 */
final readonly class ChecklistCards
{
    public function __construct(
        private ChecklistQueries $checklistQueries,
        private TicketTurnQueries $ticketTurnQueries,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Runs inside the request transaction whose tenant is the viewer's organization; the caller must have been granted
     * TicketPermission VIEW on the ticket first.
     *
     * @return ChecklistCard|null null when the ticket has no checklist round
     */
    public function forTicket(OrganizationId $organizationId, TicketId $ticketId): ?ChecklistCard
    {
        $checklist = $this->checklistQueries->forTicket($ticketId);
        if (null === $checklist) {
            return null;
        }
        $firstItem = $checklist->items[0];
        $itemFacts = new CheckItemAccessFacts($organizationId, $firstItem->itemId, $checklist->roundId, $ticketId, $firstItem->pinNumber, $this->ticketOf($ticketId));

        return ChecklistCard::of(
            $checklist,
            $this->authorizationChecker->isGranted(ChecklistPermission::MARK_FIXED, $itemFacts),
            $this->authorizationChecker->isGranted(ChecklistPermission::CHECK, $itemFacts),
        );
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
