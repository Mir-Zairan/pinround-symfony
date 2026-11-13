<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * Home's and the Whose turn view's reads of ins_ticket_turn, of the organization of TenantContext under row-level
 * security, so they need an open transaction whose tenant is the viewer's organization.
 */
interface HomeQueries
{
    public const int SHOWN_TICKETS = 5;
    public const int SHOWN_TICKETS_OF_THE_OPENED_GROUP = 100;

    /**
     * Every person and label that open tickets wait on, with only the tickets the viewer may see (ADR-0013): the
     * counts, the oldest wait and the rows alike. The viewer's own group comes first, then the oldest wait first;
     * groups with the same oldest wait put people before labels, then go by user id or label. One query.
     *
     * @param string|null $projectId   only the tickets of that project, by its canonical lowercase uuid; null for every
     *                                 project
     * @param string|null $openedGroup the TurnGroup::groupKey() whose first SHOWN_TICKETS_OF_THE_OPENED_GROUP tickets are
     *                                 shown instead of SHOWN_TICKETS; one that matches no group opens none
     *
     * @return list<TurnGroup> no group without a ticket the viewer may see
     *
     * @throws \InvalidArgumentException when the project id is not a canonical lowercase uuid, or the opened group is
     *                                   longer than 100 characters or not UTF-8, before any query (a page answers 404)
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query
     */
    public function whoseTurn(TicketViewer $viewer, ?string $projectId, ?string $openedGroup): array;
}
