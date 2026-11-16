<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The rounds of a ticket for the people who may see them (ADR-0013, ADR-0017), a round's facts for RoundVoter, and an
 * owner's draft notes. It reads under row-level security, so it needs an open transaction whose tenant is the viewer's
 * organization.
 */
interface RoundQueries
{
    /** The newest rounds from Round 1 up that are loaded; Round 0 and the viewer's own unshared draft come on top. */
    public const int MAX_NUMBERED_ROUNDS = 20;

    /**
     * At most four queries besides the viewer's roles, whatever the number of rounds: Ticketing's visibility check of
     * the ticket, then the rounds, their screenshots and those screenshots' pins, one query each; none of the last
     * three for a ticket the viewer may not see, and none for screenshots or pins that cannot exist.
     *
     * Rounds come by number (Round 0, Round 1, then the rest), then the viewer's own unshared draft, which has none.
     * Another person's unshared draft is left out for everyone, the lead and admins included.
     *
     * Only Round 0 (the report) and drafts come with screenshots and pins; a checklist or review round has none loaded.
     *
     * Round 0, the viewer's own unshared draft and the newest MAX_NUMBERED_ROUNDS numbered rounds from Round 1 up are
     * loaded; the count of the older ones is in the answer. The cap is in the rounds query, so a ticket with a
     * thousand rounds still costs the same four queries.
     *
     * @return TicketRounds no rounds for a ticket the viewer may not see, one of another organization, or an unknown id
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function forTicket(TicketId $ticketId, UserId $viewer): TicketRounds;

    /**
     * The round as RoundPermission VIEW and EDIT decide on it, in one query; who may see it is the voter's to decide, so
     * the caller answers null and a denied VIEW alike, with 404.
     *
     * @param string $roundId the id as the request carried it, in any case
     *
     * @return RoundAccessFacts|null null for a malformed id, without a query, and for a round the tenant has not
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function accessFactsOf(string $roundId): ?RoundAccessFacts;

    /**
     * What $owner wrote in their open unshared draft round of the ticket, in one query whatever the number of pictures
     * and pins. Only that person's own draft is read: another person's draft, a shared or deleted one and another
     * organization's round never are. Pass the person asking as $owner: this method does not check who asks.
     *
     * @return RoundNotes|null null when $owner has no open unshared draft on the ticket, or the tenant has no such ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes;
}
