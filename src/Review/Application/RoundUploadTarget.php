<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * What deciding an upload to a round reads of it: its ticket, its owner, whether only the owner may see it yet, and
 * whether it still takes screenshots.
 */
final readonly class RoundUploadTarget
{
    public function __construct(
        public TicketId $ticketId,
        public UserId $ownerId,
        public bool $isUnsharedDraft,
        public bool $isOpen,
    ) {
    }
}
