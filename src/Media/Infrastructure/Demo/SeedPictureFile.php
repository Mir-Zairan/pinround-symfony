<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Demo;

use App\Media\Api\PictureFileSeeds;
use App\Media\Application\Processing\CheckedOriginal;
use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Application\Processing\ReadyVariants;
use App\Media\Application\Processing\UnknownImageFormat;
use App\Media\Application\Processing\UnreadableImageHeader;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\UploadPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Checks the file as the processing worker checks an upload (signature, type, size, dimensions, animation) and then
 * hands it to the worker's own ReadyVariants. No original is kept at an upload key: the worker deletes it once
 * processed, and nothing reads it after that.
 */
// Never registered in prod, where a media object only becomes ready after the worker has processed a real upload.
#[When('dev')]
#[When('test')]
final readonly class SeedPictureFile implements PictureFileSeeds
{
    public function __construct(
        private MediaObjects $mediaObjects,
        private ReadyVariants $readyVariants,
        private ImageHeaderReader $imageHeaderReader,
    ) {
    }

    public function addFromPng(OrganizationId $organizationId, UserId $uploader, string $pngPath, \DateTimeImmutable $at): string
    {
        $original = $this->checkedPngAt($pngPath);
        $id = $this->mediaObjects->nextIdentity();
        $picture = MediaObject::upload($id, $organizationId, 'uploads/'.$organizationId->toString().'/'.$id->toString(), ImageFormat::Png->value, \strlen($original->bytes), $uploader, $at);
        $rejectReason = $this->readyVariants->markReady($picture, $original);
        if (null !== $rejectReason) {
            throw new \InvalidArgumentException(\sprintf('The picture %s could not be processed: %s', basename($pngPath), $rejectReason->value));
        }
        $this->mediaObjects->add($picture);

        return $id->toString();
    }

    private function checkedPngAt(string $pngPath): CheckedOriginal
    {
        $bytes = is_file($pngPath) && is_readable($pngPath) ? file_get_contents($pngPath, length: UploadPolicy::MAX_BYTES + 1) : false;
        if (false === $bytes || !UploadPolicy::allowsSize(\strlen($bytes))) {
            throw new \InvalidArgumentException(\sprintf('The picture %s is no readable file of 1 byte to 15 MB.', basename($pngPath)));
        }
        try {
            $header = $this->imageHeaderReader->read(substr($bytes, 0, ImageHeaderReader::LEADING_BYTES));
        } catch (UnknownImageFormat|UnreadableImageHeader $unreadable) {
            throw new \InvalidArgumentException(\sprintf('The picture %s is no readable PNG.', basename($pngPath)), 0, $unreadable);
        }
        if (ImageFormat::Png !== $header->format || $header->isAnimated || !UploadPolicy::allowsDimensions($header->width, $header->height)) {
            throw new \InvalidArgumentException(\sprintf('The picture %s is not a still PNG within the upload limits.', basename($pngPath)));
        }

        return new CheckedOriginal($bytes, ImageFormat::Png);
    }
}
