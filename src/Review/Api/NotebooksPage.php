<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * One page of a person's notebooks (Notebooks::forOwner).
 */
final readonly class NotebooksPage
{
    /**
     * @param list<NotebookSummary> $notebooks  at most Notebooks::NOTEBOOKS_PER_PAGE, last used first
     * @param NotebookCursor|null   $nextCursor null when no notebook follows the last one
     */
    public function __construct(
        public array $notebooks,
        public ?NotebookCursor $nextCursor,
    ) {
    }
}
