<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * How many open tickets wait on each person, as one viewer may count them (ADR-0013), for the "Give it to…" picker and
 * the Team page's workload bars. It reads ins_ticket_turn of the organization of TenantContext under row-level
 * security, so it needs an open transaction whose tenant is the viewer's organization. Unlike TeamLoadQueries, these
 * counts are shown: every turn kind counts, and only tickets the viewer may see.
 */
interface WaitingTurnCounts
{
    /**
     * A ticket waits on a person while its turn row names them, whatever its kind; a done ticket has no turn row, so it
     * never counts. One query for any number of people, none for no person.
     *
     * @param list<UserId> $userIds
     * @param TeamId|null  $teamId    only the tickets of that team; null for every team and no team
     * @param string|null  $projectId only the tickets of that project, by its canonical uuid; null for every project
     *
     * @return array<string, WaitingTurnCount> by user id string, every asked person included in the asked order (once
     *                                         when asked twice), with 0 tickets and no oldest when nothing waits on them
     *
     * @throws \InvalidArgumentException when the project id is not a canonical uuid, before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query
     */
    public function waitingCountsOf(TicketViewer $viewer, array $userIds, ?TeamId $teamId, ?string $projectId): array;
}
