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
     * One query. Only tickets for everyone that the reporter may see: never a team-only ticket, not even one of the
     * reporter's own team or report, as their titles may not leave for an AI. Newest updated first, a tie in time by
     * the higher id first; a project the organization lacks has none.
     *
     * @param string           $projectId a project's uuid, as the caller may not build Ticketing's ProjectId
     * @param TicketVisibility $reporter  the predicate of the person typing the report
     *
     * @return list<IntakeCandidate> at most CANDIDATE_LIMIT
     *
     * @throws \InvalidArgumentException                  when the project id is not a canonical lowercase uuid, before
     *                                                    any query
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open or the reporter's organization is
     *                                                    not the tenant, before any query
     */
    public function openCandidatesIn(string $projectId, TicketVisibility $reporter): array;
}
