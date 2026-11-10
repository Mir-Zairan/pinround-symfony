<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Where an allowed move takes the ticket (FR-WF-16): a core step by its code, which Ticketing resolves to the
 * organization's step of that code, or a step by its id, as when a ticket goes back to its previous step.
 *
 * On every move the step being left becomes the ticket's previous_step_id; Ticketing stores that, it is not part of
 * the target.
 */
final readonly class MoveTarget
{
    private function __construct(public ?string $coreCode, public ?string $stepId)
    {
    }

    /**
     * @param string $coreCode backlog, todo, progress, review, test, input, hold or done
     */
    public static function coreStep(string $coreCode): self
    {
        return new self($coreCode, null);
    }

    /**
     * @param string $stepId the uuid of the step
     */
    public static function step(string $stepId): self
    {
        return new self(null, $stepId);
    }

    public function isCoreStep(): bool
    {
        return null !== $this->coreCode;
    }
}
