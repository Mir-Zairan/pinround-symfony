<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * One case Claude Code wrote for the extension to play live (ADR-0046), as ScenarioRules accepted it.
 */
final readonly class Scenario
{
    /**
     * @param string             $why   what in the code makes the case happen
     * @param string             $setup the data the case needs, in plain words; may be empty
     * @param list<ScenarioStep> $steps
     */
    public function __construct(
        public string $title,
        public string $why,
        public string $setup,
        public string $startUrl,
        public array $steps,
    ) {
    }

    /**
     * @return array{title: string, why: string, setup: string, start_url: string, steps: list<array<string, string|array<string, string>>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'why' => $this->why,
            'setup' => $this->setup,
            'start_url' => $this->startUrl,
            'steps' => array_map(static fn (ScenarioStep $step): array => $step->toArray(), $this->steps),
        ];
    }
}
