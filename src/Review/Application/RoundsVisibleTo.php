<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Api\RoundView;
use App\Review\Api\TicketRounds;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketVisibility;

/**
 * RoundQueries for a caller that has already built the viewer's ticket visibility, as the ticket page has, so the
 * viewer's roles and the triager are not read a second time (two queries fewer). Review's UI uses it because the Api
 * may see only SharedKernel and so cannot take a TicketVisibility.
 */
interface RoundsVisibleTo
{
    /**
     * The same rounds, cap, screenshot counts and queries as RoundQueries::forTicket for the viewer the visibility
     * belongs to, less the reads of their roles and of the triager. Only the report and the viewer's own unshared draft
     * come with their screenshots: the ticket page shows a shared draft as a row with its RoundView::$screenshotCount.
     *
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     * @throws \InvalidArgumentException when $viewer is of another organization than the tenant, before any query
     */
    public function forVisibleTo(TicketId $ticketId, TicketVisibility $viewer): TicketRounds;

    /**
     * The one shared round of the ticket with that number, as RoundQueries::forTicket lists it (the latest try of each
     * screenshot, with its pins; none loaded for a review round) but with no screenshot count, also when it
     * is older than the newest RoundQueries::MAX_NUMBERED_ROUNDS. At most four queries whatever the number of rounds,
     * screenshots and pins: Ticketing's visibility check of the ticket, the round, its screenshots and their pins.
     *
     * @param int $number 0 for the report, from 1 for the rounds after it
     *
     * @return RoundView|null null for a ticket the viewer may not see, a negative number (without a query), a number the
     *                        ticket has no round of and a deleted round; an unshared draft and the checklist have no
     *                        number, so neither is ever this
     *
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     * @throws \InvalidArgumentException when $viewer is of another organization than the tenant, before any query
     */
    public function sharedRoundOf(TicketId $ticketId, TicketVisibility $viewer, int $number): ?RoundView;

    /**
     * The hand-off round shared just before round $number: of the ticket's shared drafts and review rounds that are not
     * deleted, the one with the highest number from 1 below $number; with none, the report (Round 0), whose pictures
     * are then the Befores (ADR-0048, amended 2026-11-21). The checklist has no number and is no hand-off, so it is never this. It
     * comes with the latest try of each screenshot and its pins, as sharedRoundOf gives a shared draft and the report,
     * and a review round with its "after" pictures by position; the round page pairs its screenshot N with round
     * $number's screenshot N as Before and After. At most four queries whatever the number of rounds, screenshots and
     * pins: Ticketing's visibility check of the ticket, the round, its screenshots and their pins.
     *
     * @param int $number the round whose earlier round is wanted, from 1
     *
     * @return RoundView|null null for a ticket the viewer may not see, a $number below 1 (without a query) and a ticket
     *                        with neither such a round nor a report
     *
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     * @throws \InvalidArgumentException when $viewer is of another organization than the tenant, before any query
     */
    public function previousHandOffRoundOf(TicketId $ticketId, TicketVisibility $viewer, int $number): ?RoundView;
}
