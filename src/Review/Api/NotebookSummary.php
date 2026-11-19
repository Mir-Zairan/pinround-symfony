<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One notebook as its owner's list shows it: no pictures or scenarios, only how many.
 */
final readonly class NotebookSummary
{
    /**
     * @param string             $id         the notebook's uuid, in lowercase
     * @param \DateTimeImmutable $lastUsedAt its last change or use, as stored, to the microsecond
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $screenshotCount,
        public int $scenarioCount,
        public \DateTimeImmutable $lastUsedAt,
    ) {
    }
}
