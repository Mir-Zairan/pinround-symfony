<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;

/**
 * One checklist item as ChecklistPermission is decided on it, and the number of its pin, as
 * ChecklistQueries::accessFactsOf reads them.
 */
final readonly class CheckItemAccessFacts
{
    /**
     * @param string                  $itemId    the item's id, lowercase
     * @param string                  $roundId   the id of its round, the checklist or a findings round, lowercase
     * @param int                     $pinNumber the number of its pin on its picture, from 1
     * @param TicketTurnSnapshot|null $ticket    the item's ticket as the caller already read it, under the same tenant in
     *                                           the same transaction, so the voter need not read it again; null for the
     *                                           voter to read it
     */
    public function __construct(
        public OrganizationId $organizationId,
        public string $itemId,
        public string $roundId,
        public TicketId $ticketId,
        public int $pinNumber,
        public ?TicketTurnSnapshot $ticket = null,
    ) {
    }
}
