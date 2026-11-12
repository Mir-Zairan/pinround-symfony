<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;

/**
 * The given steps for every team.
 */
final readonly class FixedStepQueries implements StepQueries
{
    /**
     * @param list<StepView> $steps
     */
    public function __construct(private array $steps)
    {
    }

    public function stepsFor(TeamId $teamId): array
    {
        return $this->steps;
    }

    public function moveTargetsFor(TeamId $teamId): array
    {
        throw new \LogicException('Creating a ticket reads no move targets.');
    }

    public function listedSteps(): array
    {
        throw new \LogicException('Creating a ticket lists no steps.');
    }

    public function groupOf(string $stepId): ?string
    {
        throw new \LogicException('Creating a ticket reads no step group.');
    }
}
