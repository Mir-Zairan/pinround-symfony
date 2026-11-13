<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;

/**
 * Tickets of the organization of the current transaction.
 */
interface Tickets
{
    /**
     * A new id no ticket has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): TicketId;

    /**
     * Saves the ticket at once, so rows that point at it (its history, its turn row) may follow in the same
     * transaction. It flushes the whole unit of work, so every other unsaved change of the request's entities is saved
     * with it.
     */
    public function add(Ticket $ticket): void;

    /**
     * Saves the changes of a ticket loaded with ofKey at once, raising its version by one, only when its row still has
     * the version the ticket was read with; a ticket with no change keeps its version. It then flushes the whole unit
     * of work, as add does.
     *
     * @throws TicketVersionConflict when another request saved the ticket after it was read; nothing is saved, the
     *                               ticket keeps its unsaved changes, and the caller rolls back
     * @throws \LogicException       when the ticket was not loaded or added in this request
     */
    public function save(Ticket $ticket): void;

    /**
     * The ticket of the organization with the key, loaded for a change, in one query by the (organization_id, key)
     * index. A ticket of another organization is not found. When the request has loaded the ticket already, the query
     * still runs but the same object comes back with its fields, and any unsaved change to them, kept as they were.
     * A caller that takes the key from a URL parses it with TicketKey::fromString first and answers its
     * InvalidTicketKey as not found, as it does null.
     *
     * @return Ticket|null null when the organization has no ticket with the key
     */
    public function ofKey(OrganizationId $organizationId, TicketKey $key): ?Ticket;
}
