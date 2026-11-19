<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application\Notebook;

use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookCursor;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookRows;
use App\Review\Domain\Notebook\NotebooksPage;
use App\SharedKernel\Domain\UserId;

/**
 * A second browser tab: reading $readEarlier's notebook for its owner answers it as that tab read it, whatever was
 * saved since; every other read and every write goes to the stored rows, so a save or delete over the old version is
 * refused there as it would be.
 */
final readonly class NotebookRowsReadEarlier implements NotebookRows
{
    public function __construct(private NotebookRows $rows, private Notebook $readEarlier)
    {
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
        if ($id->equals($this->readEarlier->id()) && $owner->equals($this->readEarlier->ownerId())) {
            return $this->readEarlier;
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

    public function delete(NotebookId $id, UserId $owner, ?int $expectedVersion = null): bool
    {
        return $this->rows->delete($id, $owner, $expectedVersion);
    }
}
