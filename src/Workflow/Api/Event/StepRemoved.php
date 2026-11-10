<?php

declare(strict_types=1);

namespace App\Workflow\Api\Event;

/**
 * A step was archived. Removing a step that was already removed publishes nothing.
 */
final readonly class StepRemoved implements WorkflowEvent
{
    public function __construct(
        public string $organizationId,
        public string $stepId,
        public ?string $teamId,
        public \DateTimeImmutable $removedAt,
    ) {
    }
}
