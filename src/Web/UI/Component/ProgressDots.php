<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A row of stage dots joined by lines, such as a report's Reported → Building → Checking → Done (my-reports.md, Card).
 * Its text alternative says the stage in words ("Step 2 of 4: Building"), so colour is never the only cue, and a
 * waiting stage is labelled "Waiting" on the page as well as in that text.
 */
#[AsTwigComponent]
final class ProgressDots
{
    // Props are public and mutable because the component system sets them after construction.
    /** @var list<string> the stages in order, two at least */
    public array $labels = [];

    /** The current stage, from 1. */
    public int $current = 0;

    /** Whether the ticket waits at the current stage (on hold, on a question). */
    public bool $isWaiting = false;

    #[PostMount]
    public function assertIsAStageOfTheRow(): void
    {
        if (\count($this->labels) < 2 || array_any($this->labels, static fn (mixed $label): bool => !\is_string($label) || '' === trim($label))) {
            throw new \InvalidArgumentException('ProgressDots needs two or more non-empty stage labels.');
        }
        if ($this->current < 1 || $this->current > \count($this->labels)) {
            throw new \InvalidArgumentException('ProgressDots\' current stage is from 1 to its number of stages.');
        }
    }

    /**
     * The text alternative of the whole row, for example "Step 2 of 4: Building, waiting".
     */
    public function summary(): string
    {
        return \sprintf('Step %d of %d: %s%s', $this->current, \count($this->labels), $this->labels[$this->current - 1], $this->isWaiting ? ', waiting' : '');
    }

    /**
     * @return list<array{label: string, state: 'passed'|'current'|'later', hasLineAfter: bool}> the label of the current
     *                                                                                           stage is "Waiting"
     *                                                                                           while the ticket waits
     */
    public function dots(): array
    {
        $lastIndex = \count($this->labels) - 1;
        $dots = [];
        foreach ($this->labels as $index => $label) {
            $place = $index + 1;
            $isCurrent = $place === $this->current;
            $dots[] = [
                'label' => $isCurrent && $this->isWaiting ? 'Waiting' : $label,
                'state' => $isCurrent ? 'current' : ($place < $this->current ? 'passed' : 'later'),
                'hasLineAfter' => $index < $lastIndex,
            ];
        }

        return $dots;
    }
}
