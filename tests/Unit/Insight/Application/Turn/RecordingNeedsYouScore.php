<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application\Turn;

use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NeedsYouScore;
use App\Workflow\Api\ReleasePlacement;

/**
 * A score of 77 for everything, keeping what it was asked last.
 */
final class RecordingNeedsYouScore implements NeedsYouScore
{
    public const int SCORE = 77;

    /** @var array{NeedsYouKind, ?string, ReleasePlacement, bool}|null */
    public ?array $lastAsked = null;

    public function baseScore(NeedsYouKind $kind, ?string $priority, ReleasePlacement $release, bool $isRiskCheck): int
    {
        $this->lastAsked = [$kind, $priority, $release, $isRiskCheck];

        return self::SCORE;
    }
}
