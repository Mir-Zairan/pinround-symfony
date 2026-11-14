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
     * transaction.
     *
     * @throws \LogicException when no transaction is open
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
     * to change it meanwhile. The object is read fresh: unsaved changes to one already loaded are lost.
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
}
