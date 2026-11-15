<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * What an organization's files take of its storage quota (media-pipeline.md, Quotas), and the lock that makes a
 * begin's checks and the intent it stores one step.
 */
interface StorageQuota
{
    /**
     * Holds the organization's storage quota until the open transaction ends, so a second begin of the same
     * organization waits here and then counts the intent of the first one. Other organizations do not wait.
     *
     * @throws \LogicException when no transaction is open
     */
    public function lock(OrganizationId $organizationId): void;

    /**
     * The bytes the organization's pending and ready media objects take plus the max bytes of its intents neither used
     * nor expired by $at; 0 when there are none. Both are read at one moment, so an upload completed meanwhile is
     * counted exactly once, as its intent or as its media object. A rejected object's file is deleted from the bucket
     * (media-pipeline.md, Validation rules), so it takes none. Row-level security hides another organization than the
     * tenant, whose total is then 0.
     *
     * @throws \LogicException when no transaction is open
     */
    public function bytesInUseAt(OrganizationId $organizationId, \DateTimeImmutable $at): int;
}
