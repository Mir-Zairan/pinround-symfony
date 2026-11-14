<?php

declare(strict_types=1);

namespace App\Notification\Domain;

interface Pings
{
    public function nextIdentity(): PingId;

    /**
     * Stores the ping at once (flushes), under the tenant of the open transaction.
     *
     * @throws \LogicException when no transaction is open
     */
    public function add(Ping $ping): void;
}
