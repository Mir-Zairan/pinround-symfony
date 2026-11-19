<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookCursor;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookRows;
use App\Review\Domain\Notebook\NotebooksPage;
use App\SharedKernel\Domain\UserId;

/**
 * The real NotebookRows, as another tab changes the notebook between a page's first read and its write. Deleted
 * meanwhile: every read after the first finds no notebook, as DbalNotebooks finds none once it is gone. Saved
 * meanwhile: every read finds it, but delete removes nothing, as DbalNotebooks answers for another version. The other
 * tab's change is not really made, so the rows show what the refused request left.
 */
final class NotebookRowsChangedMeanwhile implements NotebookRows
{
    private int $readCount = 0;

    private function __construct(private readonly NotebookRows $rows, private readonly bool $isDeletedMeanwhile)
    {
    }

    public static function deletedAfterTheFirstRead(NotebookRows $rows): self
    {
        return new self($rows, true);
    }

    public static function savedBeforeTheDelete(NotebookRows $rows): self
    {
        return new self($rows, false);
    }

    public function nextId(): NotebookId
    {
        return $this->rows->nextId();
    }

    public function insert(Notebook $notebook): void
    {
        $this->rows->insert($notebook);
    }

    public function ofOwner(NotebookId $id, UserId $owner): ?Notebook
    {
        ++$this->readCount;
        if ($this->isDeletedMeanwhile && $this->readCount > 1) {
            return null;
        }

        return $this->rows->ofOwner($id, $owner);
    }

    public function forOwner(UserId $owner, ?NotebookCursor $after, int $pageSize): NotebooksPage
    {
        return $this->rows->forOwner($owner, $after, $pageSize);
    }

    public function save(Notebook $notebook): bool
    {
        return $this->rows->save($notebook);
    }

    public function markUsed(NotebookId $id, UserId $owner, \DateTimeImmutable $at): bool
    {
        return $this->rows->markUsed($id, $owner, $at);
    }

    /**
     * Removes nothing: the notebook is gone or at another version by now.
     */
    public function delete(NotebookId $id, UserId $owner, ?int $expectedVersion = null): bool
    {
        return false;
    }
}
