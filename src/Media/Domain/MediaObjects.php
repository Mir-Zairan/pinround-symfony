<?php

declare(strict_types=1);

namespace App\Media\Domain;

interface MediaObjects
{
    public function nextIdentity(): MediaId;

    /**
     * Stores the media object at once (flushes), under the tenant of the open transaction.
     *
     * @throws \LogicException when no transaction is open
     */
    public function add(MediaObject $mediaObject): void;

    /**
     * Stores the changes of a media object read through this port at once (flushes), under the tenant of the open
     * transaction. A copy read before lockById() of the same id is no longer one the port tracks: save the locked one.
     *
     * @throws \LogicException when no transaction is open, or when $mediaObject was not read through this port in this
     *                         unit of work or was replaced by lockById(); nothing is written then
     */
    public function save(MediaObject $mediaObject): void;

    /**
     * The media object of the tenant's organization, or null when there is none with that id.
     *
     * @throws \LogicException when no transaction is open
     */
    public function byId(MediaId $id): ?MediaObject;

    /**
     * Like byId(), and the row stays locked until the open transaction ends, so the processing worker is the only one
     * to change it meanwhile. The object is read fresh: unsaved changes to one already loaded are lost, and save()
     * refuses that older copy.
     *
     * @throws \LogicException when no transaction is open, before any read
     */
    public function lockById(MediaId $id): ?MediaObject;

    /**
     * The tenant's oldest ready media object with that hash, ordered by creation time and then by id; null when there
     * is none. Pending and rejected objects are never returned.
     *
     * @param string $sha256 64 lowercase hex digits
     *
     * @throws \LogicException when no transaction is open
     */
    public function readyBySha256(string $sha256): ?MediaObject;

    /**
     * The bytes the tenant's pending and ready media objects take; 0 when there are none. A rejected object's file is
     * deleted from the bucket (media-pipeline.md, Validation rules), so it takes none.
     *
     * @throws \LogicException when no transaction is open
     */
    public function storedBytes(): int;
}
