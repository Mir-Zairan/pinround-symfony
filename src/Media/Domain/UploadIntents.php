<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\SharedKernel\Domain\UserId;

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
     * How many of the tenant's intents $userId began that are neither used nor expired by $at; 0 when there are none.
     *
     * @throws \LogicException when no transaction is open
     */
    public function openCountOf(UserId $userId, \DateTimeImmutable $at): int;
}
