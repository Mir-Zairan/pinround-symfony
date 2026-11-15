<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\SharedKernel\Domain\OrganizationId;

interface UploadIntents
{
    public function nextIdentity(): UploadIntentId;

    /**
     * Stores the intent at once (flushes), under the tenant of the open transaction.
     *
     * @throws \LogicException when no transaction is open
     */
    public function add(UploadIntent $intent): void;

    /**
     * The intent of the tenant's organization, or null when there is none with that id (or it belongs to another
     * organization).
     *
     * @throws \LogicException when no transaction is open
     */
    public function byId(UploadIntentId $id): ?UploadIntent;

    /**
     * Marks the intent used at $at by writing it conditionally, so that two uses at once cannot both succeed, and
     * updates $intent in memory to match. $at is also the time the expiry is checked against.
     *
     * @throws UploadIntentUnusable when the intent is used already or has expired by $at; nothing is written then
     * @throws \LogicException      when no transaction is open, or when the tenant has no stored intent with that id
     *                              (it belongs to another organization or was never added), which a caller answers
     *                              as not found; nothing is written then
     */
    public function recordUse(UploadIntent $intent, \DateTimeImmutable $at): void;

    /**
     * Holds the organization's storage quota until the open transaction ends, so a second begin of the same
     * organization waits here and then counts the intent of the first one. Other organizations do not wait.
     *
     * @throws \LogicException when no transaction is open
     */
    public function lockQuotaOf(OrganizationId $organizationId): void;

    /**
     * The bytes the tenant's open intents may still put in the bucket: the sum of max bytes of those neither used nor
     * expired by $at; 0 when there are none. A used intent's file is counted as its media object instead.
     *
     * @throws \LogicException when no transaction is open
     */
    public function openBytesAt(\DateTimeImmutable $at): int;
}
