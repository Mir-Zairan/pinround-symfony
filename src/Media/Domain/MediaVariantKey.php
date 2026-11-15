<?php

declare(strict_types=1);

namespace App\Media\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * The bucket key of a stored variant: `media/{orgId}/{yyyy}/{mm}/{mediaId}/{variant}.webp` (media-pipeline.md, Storage
 * key layout). Built from typed ids and the upload time only, so no user text can reach the key, and the same media
 * object always gets the same keys, however often its processing is retried.
 */
final readonly class MediaVariantKey
{
    private function __construct(private string $value)
    {
    }

    /**
     * @param \DateTimeImmutable $uploadedAt the media object's creation time; its UTC year and month go in the key
     */
    public static function of(OrganizationId $organizationId, MediaId $mediaId, \DateTimeImmutable $uploadedAt, MediaVariant $variant): self
    {
        return new self(\sprintf(
            'media/%s/%s/%s/%s.webp',
            $organizationId->toString(),
            $uploadedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y/m'),
            $mediaId->toString(),
            $variant->value,
        ));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
