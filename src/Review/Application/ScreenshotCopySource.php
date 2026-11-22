<?php

declare(strict_types=1);

namespace App\Review\Application;

/**
 * Where a draft's screenshot came from when it is a copy ("Pin this in my draft" reuses the stored picture), as
 * RoundsVisibleTo::copySourceOf finds it.
 */
final readonly class ScreenshotCopySource
{
    /**
     * @param string   $mediaId     the picture the copy shares with its source
     * @param int|null $roundNumber the highest-numbered shared round below the draft that holds the picture; null when
     *                              only rounds that are deleted, unshared or not below the draft hold it
     */
    public function __construct(
        public string $mediaId,
        public ?int $roundNumber,
    ) {
    }
}
