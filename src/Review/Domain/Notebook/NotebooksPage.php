<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

/**
 * A page of an owner's notebooks, last used first.
 */
final readonly class NotebooksPage
{
    /**
     * @param list<NotebookSummary> $notebooks
     * @param NotebookCursor|null   $nextCursor null when no notebook follows this page
     */
    public function __construct(
        public array $notebooks,
        public ?NotebookCursor $nextCursor,
    ) {
    }
}
