<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application;

use App\Workflow\Api\StepOccupancy;

/**
 * The same number of open tickets in every step; it keeps the ids of the steps it was asked about, in order.
 */
final class FixedStepOccupancy implements StepOccupancy
{
    /** @var list<string> */
    public array $askedStepIds = [];

    public function __construct(private readonly int $openTickets)
    {
    }

    public function openTicketsIn(string $stepId): int
    {
        $this->askedStepIds[] = $stepId;

        return $this->openTickets;
    }
}
