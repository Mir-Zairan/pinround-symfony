<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * A ticket's key by its id, for a module that labels a record with it. It reads the organization of TenantContext under
 * row-level security; under another tenant it finds nothing.
 */
interface TicketKeyQueries
{
    /**
     * One query by the ticket's primary key. It does not check that anyone may see the ticket.
     *
     * @return string|null the key, for example "SHOP-12"; null when the organization has no ticket with this id
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     */
    public function keyOf(TicketId $ticketId): ?string;
}
