<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The part of a needs-you item's score that does not change with time (workflow-engine.md, "Needs-you kinds and
 * score"), stored as base_score. The waiting-days part is added in SQL at read time.
 */
interface NeedsYouScore
{
    /**
     * Kind base + priority weight + release points; a check whose reason is "risk" adds 40 to the check's base.
     *
     * @param string|null $priority    one of blocker, critical, major, normal, minor; null counts as no weight
     * @param bool        $isRiskCheck true when the check reason "risk" is set; counts for the kind check only
     *
     * @throws \InvalidArgumentException when the priority is not one of the five
     */
    public function baseScore(NeedsYouKind $kind, ?string $priority, ReleasePlacement $release, bool $isRiskCheck = false): int;
}
