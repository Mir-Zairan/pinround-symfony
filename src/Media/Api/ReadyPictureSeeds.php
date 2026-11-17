<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * A picture already processed, for the dev and test environments only: browser tests have no processing worker, so
 * they cannot wait for an upload to become ready. Nothing implements it in prod, so a caller there cannot be wired.
 */
interface ReadyPictureSeeds
{
    /**
     * Adds a ready media object uploaded by $uploader, with no file behind its variants. It writes under row-level
     * security, so it needs an open transaction whose tenant is $organizationId, and is kept only if that commits.
     *
     * @return string the id of the new media object, a UUIDv7
     *
     * @throws \LogicException when no transaction is open
     */
    public function add(OrganizationId $organizationId, UserId $uploader, \DateTimeImmutable $at): string;

    /**
     * Adds a media object uploaded by $uploader that stays pending, as one the worker has not processed yet, so a page
     * shows its "Processing…" placeholder. The same transaction rules as add.
     *
     * @return string the id of the new media object, a UUIDv7
     *
     * @throws \LogicException when no transaction is open
     */
    public function addPending(OrganizationId $organizationId, UserId $uploader, \DateTimeImmutable $at): string;
}
