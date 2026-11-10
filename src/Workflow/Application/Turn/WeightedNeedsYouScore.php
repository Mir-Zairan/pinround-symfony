<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NeedsYouScore;
use App\Workflow\Api\ReleasePlacement;

/**
 * The weights of workflow-engine.md, "Needs-you kinds and score"; the contract is on {@see NeedsYouScore}.
 */
final readonly class WeightedNeedsYouScore implements NeedsYouScore
{
    public function baseScore(NeedsYouKind $kind, ?string $priority, ReleasePlacement $release, bool $isRiskCheck): int
    {
        return $this->kindBase($kind) + (NeedsYouKind::Check === $kind && $isRiskCheck ? 40 : 0) + $this->priorityWeight($priority) + match ($release) {
            ReleasePlacement::InCurrentRelease => 30,
            ReleasePlacement::InOtherRelease => 10,
            ReleasePlacement::NoRelease => 0,
        };
    }

    private function kindBase(NeedsYouKind $kind): int
    {
        return match ($kind) {
            NeedsYouKind::Answer => 100,
            NeedsYouKind::Check => 70,
            NeedsYouKind::Review, NeedsYouKind::Test => 30,
            NeedsYouKind::Assign => 25,
            NeedsYouKind::Input => 20,
            NeedsYouKind::Decide => 15,
            NeedsYouKind::Build => 10,
        };
    }

    private function priorityWeight(?string $priority): int
    {
        return match ($priority) {
            null => 0,
            'blocker' => 50,
            'critical' => 40,
            'major' => 20,
            'normal' => 8,
            'minor' => 3,
            default => throw new \InvalidArgumentException('A priority must be blocker, critical, major, normal or minor.'),
        };
    }
}
