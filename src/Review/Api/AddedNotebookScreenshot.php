<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * What Notebooks::addScreenshot did: added the picture over the version it read, or found it there already.
 */
final readonly class AddedNotebookScreenshot
{
    /**
     * @param bool $wasAddedBefore  true when the notebook held the picture already and nothing was saved
     * @param int  $screenshotCount the pictures the notebook holds after the call: as saved over the version read, or
     *                              as read when nothing was saved
     */
    public function __construct(
        public bool $wasAddedBefore,
        public int $screenshotCount,
    ) {
    }
}
