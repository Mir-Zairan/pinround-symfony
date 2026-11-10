<?php

declare(strict_types=1);

namespace App\Workflow\Api\Event;

/**
 * An event of the Workflow module. It goes to the outbox in the transaction of the change and on to async_notify
 * (messenger.yaml), and is handled under its organization. Ids are canonical UUID strings, and no event carries a step
 * name or other free text (ADR-0027).
 */
interface WorkflowEvent
{
    public string $organizationId { get; }

    /** The team whose steps or rules changed; null for the organization's own. */
    public ?string $teamId { get; }
}
