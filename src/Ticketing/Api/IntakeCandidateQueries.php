<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The open tickets of a project that a reporter's draft may duplicate, for the intake check (P08.T7). It reads the
 * organization of TenantContext under row-level security, so it needs an open transaction whose tenant is the
 * reporter's organization.
 */
interface IntakeCandidateQueries
{
    /** The most candidates openCandidatesIn() returns. */
    public const int CANDIDATE_LIMIT = 80;

    /**
     * One query. Only open tickets the reporter may see; newest updated first, a tie in time by the higher id first;
     * a project the organization lacks has none.
     *
     * @param string           $projectId       a project's uuid, as the caller may not build Ticketing's ProjectId
     * @param TicketVisibility $reporter        the predicate of the person typing the report
     * @param bool             $includeTeamOnly true adds the team-only tickets the reporter may see, for when the
     *                                          organization lets them go to this AI (data.ai.restricted_to_cloud for a
     *                                          cloud one); false leaves out every team-only ticket, even one of the
     *                                          reporter's own team or report
     *
     * @return list<IntakeCandidate> at most CANDIDATE_LIMIT
     *
     * @throws \InvalidArgumentException                  when the project id is not a canonical lowercase uuid, before
     *                                                    any query
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open or the reporter's organization is
     *                                                    not the tenant, before any query
     */
    public function openCandidatesIn(string $projectId, TicketVisibility $reporter, bool $includeTeamOnly): array;

    /**
     * The open tickets with these keys that the reporter may see, team-only ones included, for showing the duplicates
     * an intake check suggested: one query, none for no key or only keys no ticket can have.
     *
     * @param TicketVisibility $reporter the predicate of the person who asked for the check
     * @param list<string>     $keys     at most CANDIDATE_LIMIT, for example "SHOP-12"; a lowercase or malformed key,
     *                                   an unknown one, a closed ticket's and one the reporter may not see are dropped
     *
     * @return list<IntakeCandidate> in the order of $keys, each key once
     *
     * @throws \InvalidArgumentException                  when more than CANDIDATE_LIMIT keys are given, before any query
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open or the reporter's organization is
     *                                                    not the tenant, before any query
     */
    public function openCandidatesByKeys(TicketVisibility $reporter, array $keys): array;
}
