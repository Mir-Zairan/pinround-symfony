<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Demo;

/**
 * What the demo seed created and what it found already there, by kind of row, for its one summary line.
 */
final class SeededCounts
{
    /** @var array<string, array{created: list<string>, kept: list<string>}> */
    private array $byKind = [];

    public function created(string $kind, string $label): void
    {
        $this->byKind[$kind]['created'][] = $label;
    }

    public function kept(string $kind, string $label): void
    {
        $this->byKind[$kind]['kept'][] = $label;
    }

    public function summary(): string
    {
        $parts = [];
        foreach (['organizations', 'members', 'teams', 'team members'] as $kind) {
            $created = $this->byKind[$kind]['created'] ?? [];
            $kept = $this->byKind[$kind]['kept'] ?? [];
            $parts[] = \sprintf('%s: created %d, kept %d', ucfirst($kind), \count($created), \count($kept));
        }

        return implode('; ', $parts).'.';
    }
}
