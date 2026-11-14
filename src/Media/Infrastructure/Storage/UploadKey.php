<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\Uid\Uuid;

/**
 * The bucket key a browser uploads an original to: `uploads/{orgId}/{intentId}` (media-pipeline.md, Storage layout).
 * Built from typed ids only, so no user text (a file name, a `/` or `..`) can reach the key.
 */
final readonly class UploadKey
{
    private function __construct(private string $value)
    {
    }

    public static function forIntent(OrganizationId $organizationId, Uuid $intentId): self
    {
        return new self(\sprintf('uploads/%s/%s', $organizationId->toString(), $intentId->toRfc4122()));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
