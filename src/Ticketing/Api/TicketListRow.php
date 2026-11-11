<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * One ticket as the tickets list shows it.
 */
final readonly class TicketListRow
{
    /**
     * @param string      $key          for example SHOP-12
     * @param string      $type         bug, story or task
     * @param string|null $release      null when the ticket has none
     * @param string      $priority     one of blocker, critical, major, normal, minor
     * @param string|null $assigneeName null when there is no assignee, or no user has the assignee's id
     * @param UserId|null $turnUserId   whose turn it is; null when it is a label's (such as Customer) or no turn row
     *                                  exists yet
     * @param string|null $turnUserName null when $turnUserId is, or no user has that id
     * @param string      $stepId       the uuid of the step the ticket is in
     */
    public function __construct(
        public TicketId $id,
        public string $key,
        public string $title,
        public string $type,
        public ?string $release,
        public string $priority,
        public ?UserId $assigneeId,
        public ?string $assigneeName,
        public ?UserId $turnUserId,
        public ?string $turnUserName,
        public \DateTimeImmutable $updatedAt,
        public string $stepId,
    ) {
    }
}
