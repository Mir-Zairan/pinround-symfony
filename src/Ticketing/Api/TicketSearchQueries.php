<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The ticket search of ADR-0022 as a short list of open tickets, for a module that lets a person pick one, such as the
 * MCP tool find_tickets. It reads the organization of TenantContext under row-level security, so it needs an open
 * transaction whose tenant is the viewer's organization.
 */
interface TicketSearchQueries
{
    /** The most matches one call returns. */
    public const int MAX_MATCHES = 10;

    /**
     * The viewer's best matches among the open tickets (any step outside Done, archived steps included), in the
     * search's order: the exact key first, then the best match, then id. Only tickets the viewer may see are read
     * (ADR-0013): search_ticket_ids applies the visibility predicate before its cap, and its ids are joined back to
     * tkt_ticket under TicketVisibilitySql. Three queries at most whatever the number of matches: the matches in one,
     * their turn users in one and those people's names in one; none of the last two when nothing matches, and the
     * organization's steps through Workflow's StepQueries only while its cache is cold. An organization with no open
     * step runs no search.
     *
     * @param string      $searchText as ListFilter::isSearchText() allows it: trimmed, valid UTF-8 without NUL, 1 to
     *                                ListFilter::SEARCH_TEXT_MAX_LENGTH characters
     * @param string|null $projectId  a project's canonical uuid, to search that project only; null for every project
     * @param int         $limit      how many matches at most, 1 to MAX_MATCHES
     *
     * @throws \InvalidArgumentException when the search text, the project id or the limit is not one allowed here,
     *                                   before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query; when a match is in a step the
     *                                   organization does not have
     */
    public function openMatchesOf(TicketVisibility $viewer, string $searchText, ?string $projectId, int $limit): TicketMatches;
}
