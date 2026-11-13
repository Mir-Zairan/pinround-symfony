<?php

declare(strict_types=1);

namespace App\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;

/**
 * The whose-turn groups as the database returns them, for HomeQueriesService, which checks the input and orders them.
 */
interface WhoseTurnRows
{
    /**
     * One query; the counts, oldest waits and tickets hold only tickets the viewer may see.
     *
     * @param string|null $projectId   a canonical lowercase uuid, checked by the caller; null for every project
     * @param string|null $openedGroup the TurnGroup::groupKey() shown with HomeQueries::SHOWN_TICKETS_OF_THE_OPENED_GROUP
     *                                 tickets instead of HomeQueries::SHOWN_TICKETS
     *
     * @return list<TurnGroup> in no promised order, each group's tickets the oldest wait first
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query
     */
    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup): array;
}
