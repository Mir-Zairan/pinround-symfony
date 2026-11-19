<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * One step the extension plays. Which of target, text, option and url a step has depends on its type, as
 * ScenarioRules::STEP_FIELDS lists; the others are null.
 */
final readonly class ScenarioStep
{
    /**
     * @param string $type one of the keys of ScenarioRules::STEP_FIELDS
     * @param string $say  the line the player shows while the step runs
     */
    public function __construct(
        public string $type,
        public string $say,
        public ?ScenarioTarget $target = null,
        public ?string $text = null,
        public ?string $option = null,
        public ?string $url = null,
    ) {
    }

    /**
     * @return array<string, string|array<string, string>> the step as scenario.v1.json writes it
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'type' => $this->type,
                'say' => $this->say,
                'target' => $this->target?->toArray(),
                'text' => $this->text,
                'option' => $this->option,
                'url' => $this->url,
            ],
            static fn (array|string|null $value): bool => null !== $value,
        );
    }
}
