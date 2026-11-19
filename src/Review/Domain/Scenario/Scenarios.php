<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * A notebook's scenarios in their order, as ScenarioRules accepted them.
 */
final readonly class Scenarios
{
    /**
     * @param list<Scenario> $scenarios at most ScenarioRules::MAX_SCENARIOS; none is an empty notebook's
     *
     * @throws ScenarioRefused TOO_MANY_SCENARIOS when there are more, so no notebook holds more than rvw_notebook allows
     */
    public function __construct(private array $scenarios)
    {
        if (\count($scenarios) > ScenarioRules::MAX_SCENARIOS) {
            throw ScenarioRefused::because(ScenarioRefused::TOO_MANY_SCENARIOS);
        }
    }

    /**
     * @return list<Scenario>
     */
    public function all(): array
    {
        return $this->scenarios;
    }

    /**
     * @return list<array{title: string, why: string, setup: string, start_url: string, steps: list<array<string, string|array<string, string>>>}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Scenario $scenario): array => $scenario->toArray(), $this->scenarios);
    }
}
