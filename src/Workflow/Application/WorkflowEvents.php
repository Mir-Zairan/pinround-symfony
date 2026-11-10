<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Workflow\Api\Event\WorkflowEvent;

interface WorkflowEvents
{
    /**
     * Writes the event to the outbox in the caller's open transaction, so it is delivered only if the change it
     * describes commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function publish(WorkflowEvent $event): void;
}
