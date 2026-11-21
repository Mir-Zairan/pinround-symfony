<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A person's own open unshared draft round of a ticket: found without reading its pictures or pins, or with its
 * screenshots listed by chain of tries, never with pins. It reads under row-level security, so it needs an open
 * transaction whose tenant is the person's organization.
 */
interface OpenDraftQueries
{
    /**
     * $owner's open unshared draft of the ticket as RoundPermission VIEW and EDIT decide on it, in one query on the
     * index that holds at most one such draft per owner and ticket. Another person's draft, a shared, closed or deleted
     * one and another organization's round never come back. Pass the person asking as $owner: this method does not
     * check who asks, nor whether they may still see the ticket; the owner's facts grant RoundPermission without it.
     *
     * @return RoundAccessFacts|null null when $owner has no open unshared draft on the ticket, or the tenant has no such
     *                               ticket
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function openDraftOf(TicketId $ticketId, UserId $owner): ?RoundAccessFacts;

    /**
     * The same draft as openDraftOf with each of its screenshots, the latest try of every chain, numbered as the draft
     * round card numbers them (ScreenshotChains), in one query whatever the number of tries. Pins are not read. The
     * same rules as openDraftOf: only $owner's own open unshared draft, and no check of who asks or of the ticket.
     *
     * @return OpenDraftScreenshots|null null when openDraftOf is; a draft with no screenshot has no chains
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function openDraftScreenshotsOf(TicketId $ticketId, UserId $owner): ?OpenDraftScreenshots;
}
