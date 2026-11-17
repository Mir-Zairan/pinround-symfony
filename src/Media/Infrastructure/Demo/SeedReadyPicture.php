<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Demo;

use App\Media\Api\ReadyPictureSeeds;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaVariant;
use App\Media\Domain\MediaVariantKey;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\When;

// Never registered in prod, where a media object only becomes ready after the worker has processed a real file.
#[When('dev')]
#[When('test')]
final readonly class SeedReadyPicture implements ReadyPictureSeeds
{
    public function __construct(private MediaObjects $mediaObjects)
    {
    }

    public function add(OrganizationId $organizationId, UserId $uploader, \DateTimeImmutable $at): string
    {
        $id = $this->mediaObjects->nextIdentity();
        $picture = self::uploaded($id, $organizationId, $uploader, $at);
        $picture->markReady(hash('sha256', $id->toString()), 640, 480, [
            MediaVariant::View->value => MediaVariantKey::of($organizationId, $id, $at, MediaVariant::View)->toString(),
            MediaVariant::Thumb->value => MediaVariantKey::of($organizationId, $id, $at, MediaVariant::Thumb)->toString(),
        ]);
        $this->mediaObjects->add($picture);

        return $id->toString();
    }

    public function addPending(OrganizationId $organizationId, UserId $uploader, \DateTimeImmutable $at): string
    {
        $id = $this->mediaObjects->nextIdentity();
        $this->mediaObjects->add(self::uploaded($id, $organizationId, $uploader, $at));

        return $id->toString();
    }

    private static function uploaded(MediaId $id, OrganizationId $organizationId, UserId $uploader, \DateTimeImmutable $at): MediaObject
    {
        return MediaObject::upload($id, $organizationId, 'uploads/'.$organizationId->toString().'/'.$id->toString(), 'image/png', 1, $uploader, $at);
    }
}
