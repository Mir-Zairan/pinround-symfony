<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One open ticket a search found, with what a person needs to pick it as the target of their findings.
 */
final readonly class TicketMatch
{
    /**
     * @param string      $key          for example SHOP-12
     * @param string      $title        as the reporter typed it
     * @param string      $stepName     the name of the step the ticket is in, archived steps included
     * @param UserId|null $turnUserId   whose turn it is in Insight's turn read model; null when it is a label's (such
     *                                  as Customer) or no turn row exists yet
     * @param string|null $turnUserName Identity's display name of $turnUserId; null when $turnUserId is, or no user
     *                                  has that id
     */
    public function __construct(
        public TicketId $id,
        public string $key,
        public string $title,
        public string $stepName,
        public ?UserId $assigneeId,
        public ?UserId $turnUserId,
        public ?string $turnUserName,
    ) {
    }
}
