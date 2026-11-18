<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * An open ticket a viewer works on, with its saved turn (the one Home and the ticket page name).
 */
final readonly class MyTicket
{
    /**
     * @param string      $key        for example SHOP-12
     * @param string      $title      text a person typed
     * @param string      $stepId     the uuid of the step the ticket is in
     * @param TeamId|null $teamId     null for a ticket no team owns yet
     * @param UserId|null $turnUserId whose turn it is; null when it is a label's
     * @param string|null $turnLabel  who the turn waits on when that is not a user, such as Customer
     * @param string      $turnKind   one of Workflow's NeedsYouKind values
     * @param UserId|null $askerId    who asked the ticket's open question; null while none is open
     */
    public function __construct(
        public TicketId $id,
        public string $key,
        public string $title,
        public string $stepId,
        public ?TeamId $teamId,
        public ?UserId $turnUserId,
        public ?string $turnLabel,
        public string $turnKind,
        public ?UserId $assigneeId,
        public ?UserId $askerId,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
