<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\SharedKernel\Domain\TicketId;

/**
 * The open shared round a ticket's round bar names, read without locks, screenshots or pins, so the bar costs one small
 * query on every ticket view and live refresh. It reads under row-level security, so it needs an open transaction whose
 * tenant is the viewer's organization.
 */
interface OpenSharedRoundQueries
{
    /**
     * The lowest-numbered open shared round of the ticket (Round::isOpenSharedRound: an open review round or shared
     * draft), whatever its age: ADR-0048 lets one be open at a time, yet older data can hold two, and the lower is the
     * one a share waits for. One query, whatever the number of rounds, screenshots and pins.
     *
     * Every shared round is seen by everyone who may see the ticket, so this method checks nobody: the caller has already
     * checked that the viewer may see the ticket.
     *
     * @return OpenSharedRound|null null when no shared round of the ticket is open, or the tenant has no such ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function lowestOpenSharedRoundOf(TicketId $ticketId): ?OpenSharedRound;
}
