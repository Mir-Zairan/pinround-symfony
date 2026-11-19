<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

/**
 * One line of an owner's notebook list: what the list shows, without the screenshots and scenarios themselves.
 */
final readonly class NotebookSummary
{
    public function __construct(
        public NotebookId $id,
        public string $title,
        public int $screenshotCount,
        public int $scenarioCount,
        public \DateTimeImmutable $lastUsedAt,
    ) {
    }
}
