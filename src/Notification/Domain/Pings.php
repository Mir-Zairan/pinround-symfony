<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

interface Pings
{
    public function nextIdentity(): PingId;

    /**
     * Stores the ping at once (flushes), under the tenant of the open transaction.
     *
     * @throws \LogicException when no transaction is open
     */
    public function add(Ping $ping): void;

    /**
     * Stores the changes of a ping read through this port at once (flushes), under the tenant of the open transaction.
     *
     * @throws \LogicException when no transaction is open
     */
    public function save(Ping $ping): void;

    /**
     * The pings to $toId about $ticketId that are not cleared, of the tenant's organization, oldest first, each locked
     * until the open transaction ends. A call that waited for another transaction's lock finds only the pings that are
     * still open once that one committed, so two clearings at once never clear the same ping twice. Each ping is read
     * fresh: unsaved changes to one already loaded are lost.
     *
     * @return list<Ping>
     *
     * @throws \LogicException when no transaction is open, before any read
     */
    public function lockedOpenPingsTo(UserId $toId, TicketId $ticketId): array;
}
