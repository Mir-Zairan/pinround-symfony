<?php

declare(strict_types=1);

namespace App\Workflow\Api\Event;

/**
 * A company step (no team) or a team step was added.
 */
final readonly class StepAdded implements WorkflowEvent
{
    public function __construct(
        public string $organizationId,
        public string $stepId,
        public ?string $teamId,
        public string $groupCode,
    ) {
    }
}
