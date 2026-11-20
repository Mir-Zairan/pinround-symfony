<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The screenshots of a round as chains of tries (ADR-0048), numbered as the draft round card shows them: "Screenshot n"
 * is the chain at index n - 1. The Rounds section and the agent's list of a draft's screenshots both number from here,
 * so a "Retake of screenshot N" names the screenshot the card calls N.
 */
final class ScreenshotChains
{
    /**
     * @param list<RoundScreenshotView> $screenshots by the position of each one's chain's first try, then by position,
     *                                               as RoundView lists them
     *
     * @return list<non-empty-list<RoundScreenshotView>> by their first try's position; each chain's tries by position,
     *                                                   the latest last
     */
    public static function of(array $screenshots): array
    {
        $chains = [];
        foreach ($screenshots as $screenshot) {
            $chains[$screenshot->retakeOf ?? $screenshot->id][] = $screenshot;
        }

        return array_values($chains);
    }
}
