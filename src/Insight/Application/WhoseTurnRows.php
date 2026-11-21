<?php

declare(strict_types=1);

namespace App\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;
use App\Insight\Api\ViewersTurns;

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
     * @param string|null          $searchText      only the tickets the tickets list's search finds, as
     *                                              ListFilter::isSearchText allows it, checked by the caller; null for
     *                                              every ticket
     *
     * @return array{groups: list<TurnGroup>, ticketCount: int, isSearchCapped: bool} the groups in no promised order,
     *                                                                                each group's tickets the oldest wait
     *                                                                                first; every ticket the viewer may
     *                                                                                see, of every group; and true when
     *                                                                                the search found more than
     *                                                                                TicketListStep::COUNT_CAP tickets, of
     *                                                                                which only the best count
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query
     */
    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup, ?TurnGroupCursor $after, int $otherGroupLimit, ?string $searchText = null): array;

    /**
     * One query on the viewer's own turn rows only; the count and tickets hold only tickets the viewer may see.
     *
     * @param int $limit at most this many tickets, checked by the caller
     *
     * @return ViewersTurns the oldest wait first, then the lowest ticket id, as groupedRowsOf() orders a group's tickets
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query
     */
    public function viewersTurnsOf(TicketViewer $viewer, int $limit): ViewersTurns;
}
