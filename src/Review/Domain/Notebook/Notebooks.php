<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

use App\SharedKernel\Domain\UserId;

/**
 * The notebooks of the tenant's people. Each method needs an open transaction under the organization as tenant, and
 * reads or writes that organization's rows only; every read and write names the owner, so another person's notebook
 * is not found exactly like one that does not exist.
 */
interface Notebooks
{
    public const int MAX_PAGE_SIZE = 100;

    public function nextId(): NotebookId;

    /**
     * @throws \LogicException when no transaction is open, no tenant is set, or the tenant is not the notebook's
     *                         organization
     */
    public function insert(Notebook $notebook): void;

    /**
     * @return Notebook|null null when the tenant has no such notebook of $owner
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function ofOwner(NotebookId $id, UserId $owner): ?Notebook;

    /**
     * $owner's notebooks, last used first and then by id descending, a keyset page at a time.
     *
     * @param NotebookCursor|null $after    the nextCursor of the page before, null for the first page
     * @param int                 $pageSize from 1 to MAX_PAGE_SIZE
     *
     * @throws \InvalidArgumentException when $pageSize is outside 1 to MAX_PAGE_SIZE
     * @throws \LogicException           when no transaction is open or no tenant is set
     */
    public function forOwner(UserId $owner, ?NotebookCursor $after, int $pageSize): NotebooksPage;

    /**
     * Writes the notebook's title, screenshots, scenarios and times only while the stored version is still
     * $notebook->version(); the stored version then goes up by one. Read the notebook again to change it once more.
     *
     * @return bool false when the notebook is gone, is not its owner's, or changed since it was read; nothing is
     *              written then
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function save(Notebook $notebook): bool;

    /**
     * @param int|null $expectedVersion when given, removes the notebook only while it is still at that version
     *
     * @return bool false when the tenant has no such notebook of $owner, or it is at another version; nothing is
     *              removed then
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function delete(NotebookId $id, UserId $owner, ?int $expectedVersion = null): bool;
}
