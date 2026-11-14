<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\Media\Domain\UploadIntentId;
use App\SharedKernel\Domain\OrganizationId;

/**
 * The bucket key a browser uploads an original to: `uploads/{orgId}/{intentId}` (media-pipeline.md, Storage layout).
 * Built from typed ids only, so no user text (a file name, a `/` or `..`) can reach the key.
 */
final readonly class UploadKey
{
    private function __construct(private string $value)
    {
    }

    public static function forIntent(OrganizationId $organizationId, UploadIntentId $intentId): self
    {
        return new self(\sprintf('uploads/%s/%s', $organizationId->toString(), $intentId->toString()));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
