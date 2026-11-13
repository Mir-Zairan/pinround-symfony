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
     * The ticket of the organization with the key, loaded for a change; one query unless the request has loaded it
     * already. A ticket of another organization is not found. A caller that takes the key from a URL answers
     * InvalidTicketKey as not found, as it does null.
     *
     * @param string $key the key as people write it, for example "SHOP-12"
     *
     * @return Ticket|null null when the organization has no ticket with the key
     *
     * @throws InvalidTicketKey when the key is not a project key, a hyphen and a number from 1 up, before any query
     */
    public function ofKey(OrganizationId $organizationId, string $key): ?Ticket;
}
