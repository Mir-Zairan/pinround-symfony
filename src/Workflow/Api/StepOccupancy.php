<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * How many open tickets are in a step, asked before a step is removed (workflow-engine.md, "Company steps, team steps
 * and the step policy"). Ticketing owns the tickets and implements it, so Workflow need not call Ticketing, which
 * already calls Workflow.
 */
interface StepOccupancy
{
    /**
     * Reads under row-level security, so it needs an open transaction whose tenant is the step's organization; under
     * another tenant it counts nothing.
     *
     * @param string $stepId a step's uuid, lowercase
     *
     * @return int the open tickets in the step, zero or more
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function openTicketsIn(string $stepId): int;
}
