<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * A notebook's scenarios in their order, as ScenarioRules accepted them.
 */
final readonly class Scenarios
{
    /**
     * @param list<Scenario> $scenarios
     */
    public function __construct(private array $scenarios)
    {
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
