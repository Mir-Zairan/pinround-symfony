<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Ticketing\Api\Event\TicketEvent;

interface TicketEvents
{
    /**
     * Writes the event to the outbox in the caller's open transaction, so it is delivered only if the change it
     * describes commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function publish(TicketEvent $event): void;
}
