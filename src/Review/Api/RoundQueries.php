<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The rounds of a ticket for the people who may see them (ADR-0013, ADR-0017), a round's facts for RoundVoter, an
 * owner's draft notes and the newest shared round's notes. It reads under row-level security, so it needs an open transaction whose tenant is the viewer's
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
     * The one draft round $roundId with all its screenshots and pins, in at most three queries (the round, its
     * screenshots, their pins) and none of any other round. Only $owner's own undeleted draft, shared since or not, is
     * read: another person's draft, a round that is no draft, a deleted one, another organization's round and an
     * unknown or malformed id are all null. Pass the person asking as $owner: this method does not check who asks.
     *
     * @param string $roundId the id as the request carried it, in any case
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function draftOf(string $roundId, UserId $owner): ?RoundView;

    /**
     * What $owner wrote in their open unshared draft round of the ticket, in one query whatever the number of pictures
     * and pins. Only that person's own draft is read: another person's draft, a shared or deleted one and another
     * organization's round never are. Pass the person asking as $owner: this method does not check who asks.
     *
     * Only the pins that count come back: a person's, and an AI pin a person kept (FR-RND-07, ADR-0020). The draft
     * editor still shows a suggested AI pin, marked as the AI's, for its owner to keep or dismiss; it reads the draft
     * through forTicket and draftOf, not here.
     *
     * @return RoundNotes|null null when $owner has no open unshared draft on the ticket, or the tenant has no such ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes;

    /**
     * The newest shared round of the ticket that holds pictures, the report (Round 0) or a shared draft round, by
     * number, with its pictures and the pins that count, in one query whatever the number of pictures and pins. A
     * checklist or review round, a deleted round and an unshared draft are never it; neither is another organization's.
     * A suggested or dismissed AI pin is left out, as in roundNotes.
     *
     * This method checks nobody: the caller asks TicketPermission VIEW on the ticket first, and RoundPermission VIEW on
     * the round it answers.
     *
     * @return SharedRoundNotes|null null when the ticket has no shared report or draft round, or the tenant has no such
     *                               ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function sharedRoundNotes(TicketId $ticketId): ?SharedRoundNotes;
}
