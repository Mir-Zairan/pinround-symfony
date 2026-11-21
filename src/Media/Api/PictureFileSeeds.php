<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * A picture made from a PNG file in the repository and processed at once, for the dev environment's demo seed: its
 * showcase tickets must show real pictures, and a report or round only takes a picture that is ready. Nothing
 * implements it in prod, so a caller there cannot be wired. The file is read from disk by a console seed, never
 * uploaded through a web request (ADR-0011).
 */
interface PictureFileSeeds
{
    /**
     * Adds a ready media object uploaded by $uploader from the PNG at $pngPath, whose view and thumb variants are
     * re-encoded and written to the bucket as the processing worker writes them; a ready picture of the organization
     * with the same bytes lends its variants instead. It writes under row-level security, so it needs an open
     * transaction whose tenant is $organizationId, and the row is kept only if that commits; written variants stay in
     * the bucket either way.
     *
     * @return string the id of the new media object, a UUIDv7
     *
     * @throws \InvalidArgumentException when $pngPath is no readable file, or not a still PNG within the upload limits;
     *                                   nothing is stored
     * @throws \RuntimeException         when the bucket cannot be written or the encoder cannot run
     * @throws \LogicException           when no transaction is open
     */
    public function addFromPng(OrganizationId $organizationId, UserId $uploader, string $pngPath, \DateTimeImmutable $at): string;
}
