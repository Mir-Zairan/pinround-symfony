<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;

/**
 * One checklist item as ChecklistPermission is decided on it, and the number of its pin, as
 * ChecklistQueries::accessFactsOf reads them.
 */
final readonly class CheckItemAccessFacts
{
    /**
     * @param string $itemId    the item's id, lowercase
     * @param string $roundId   the id of its checklist round, lowercase
     * @param int    $pinNumber the number of its pin on its Round 0 picture, from 1
     */
    public function __construct(
        public OrganizationId $organizationId,
        public string $itemId,
        public string $roundId,
        public TicketId $ticketId,
        public int $pinNumber,
    ) {
    }
}
