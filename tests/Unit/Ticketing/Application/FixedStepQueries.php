<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;

/**
 * The given steps and move targets for every team.
 */
final readonly class FixedStepQueries implements StepQueries
{
    /**
     * @param list<StepView>      $steps
     * @param list<StepView>|null $moveTargets null for a caller that must read none
     */
    public function __construct(private array $steps, private ?array $moveTargets = null)
    {
    }

    public function stepsFor(TeamId $teamId): array
    {
        return $this->steps;
    }

    public function moveTargetsFor(TeamId $teamId): array
    {
        return $this->moveTargets ?? throw new \LogicException('This caller reads no move targets.');
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
