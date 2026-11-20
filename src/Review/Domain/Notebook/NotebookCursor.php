<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

/**
 * Where the next page of an owner's notebooks starts: after the notebook last used at $lastUsedAt with id $notebookId,
 * in the list's order (last used first, then id descending).
 */
final readonly class NotebookCursor
{
    public function __construct(
        public \DateTimeImmutable $lastUsedAt,
        public NotebookId $notebookId,
    ) {
    }
}
