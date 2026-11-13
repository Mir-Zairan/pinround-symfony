<?php

declare(strict_types=1);

namespace App\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;

/**
 * The whose-turn groups as the database returns them, for HomeQueriesService, which checks the input, orders them and
 * cuts the page.
 */
interface WhoseTurnRows
{
    /**
     * One query; the counts, oldest waits and tickets hold only tickets the viewer may see.
     *
     * @param string|null          $projectId       a canonical lowercase uuid, checked by the caller; null for every project
     * @param string|null          $openedGroup     the TurnGroup::groupKey() shown with
     *                                              HomeQueries::SHOWN_TICKETS_OF_THE_OPENED_GROUP tickets instead of
     *                                              HomeQueries::SHOWN_TICKETS
     * @param TurnGroupCursor|null $after           null to add the viewer's own group; else only the groups after it
     * @param int                  $otherGroupLimit at most this many groups besides the viewer's: the first in
     *                                              HomeQueries::whoseTurn's order, after $after
     *
     * @return array{groups: list<TurnGroup>, ticketCount: int} the groups in no promised order, each group's tickets the
     *                                                          oldest wait first; and every ticket the viewer may see, of
     *                                                          every group
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query
     */
    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup, ?TurnGroupCursor $after, int $otherGroupLimit): array;
}
