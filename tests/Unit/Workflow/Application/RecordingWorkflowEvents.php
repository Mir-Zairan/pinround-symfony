<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application;

use App\Workflow\Api\Event\WorkflowEvent;
use App\Workflow\Application\WorkflowEvents;

final class RecordingWorkflowEvents implements WorkflowEvents
{
    /** @var list<WorkflowEvent> */
    public array $published = [];

    public function publish(WorkflowEvent $event): void
    {
        $this->published[] = $event;
    }
}
