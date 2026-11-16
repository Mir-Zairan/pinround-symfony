<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * What RoundPermission VIEW and EDIT are decided on of one round, as RoundQueries::accessFactsOf reads it.
 */
final readonly class RoundAccessFacts
{
    /**
     * @param string $roundId         the round's id, lowercase
     * @param bool   $isUnsharedDraft true for a draft nobody but its owner may see yet
     * @param bool   $isDeleted       true for a draft its owner deleted, which nobody sees any more
     */
    public function __construct(
        public OrganizationId $organizationId,
        public string $roundId,
        public TicketId $ticketId,
        public UserId $ownerId,
        public bool $isUnsharedDraft,
        public bool $isDeleted,
    ) {
    }
}
