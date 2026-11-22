<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Domain\UserId;

/**
 * "Me" on the tickets list: the tickets assigned to the viewer and those waiting on their turn, as a tester in Testing
 * or a reviewer in Code review, though someone else is the assignee. Another person's chip keeps "assigned to" only.
 */
final readonly class ListTurnTickets
{
    /** The most tickets of a viewer's turn a list takes besides the assigned ones; Whose turn? shows every one. */
    public const int MAX = 500;

    public function __construct(private TurnUserQueries $turnUserQueries)
    {
    }

    /**
     * One query when the list's person is the viewer, none otherwise. Runs inside the request transaction whose tenant is
     * the viewer's organization.
     *
     * @return list<string> ticket ids, for ListFilter's $alsoTicketIds
     */
    public function of(TicketListContext $context, UserId $viewerId): array
    {
        if ($context->assigneeId !== $viewerId->toString()) {
            return [];
        }

        return $this->turnUserQueries->ticketIdsWaitingOn($viewerId, self::MAX);
    }
}
