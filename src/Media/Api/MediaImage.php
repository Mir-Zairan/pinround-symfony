<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * One picture as a page shows it: a signed URL of one stored variant once it is ready, nothing to show while it is
 * pending, and the plain reason when processing refused it.
 */
final readonly class MediaImage
{
    /**
     * @param string|null $url     a short-lived signed GET URL; only when ready
     * @param int|null    $width   of the variant the URL shows, in pixels; null when not known
     * @param int|null    $height  of the variant the URL shows, in pixels; null when not known
     * @param string|null $refusal why processing refused the upload, in the words the person sees; only when rejected
     */
    private function __construct(
        public string $mediaId,
        public MediaImageStatus $status,
        public ?string $url,
        public ?int $width,
        public ?int $height,
        public ?string $refusal,
    ) {
    }

    public static function pending(string $mediaId): self
    {
        return new self($mediaId, MediaImageStatus::Pending, null, null, null, null);
    }

    /**
     * @throws \InvalidArgumentException when $url is empty, or $width or $height is below 1
     */
    public static function ready(string $mediaId, string $url, ?int $width, ?int $height): self
    {
        if ('' === $url) {
            throw new \InvalidArgumentException('A ready picture needs a URL.');
        }
        if ((null !== $width && $width < 1) || (null !== $height && $height < 1)) {
            throw new \InvalidArgumentException('A picture is at least one pixel wide and high.');
        }

        return new self($mediaId, MediaImageStatus::Ready, $url, $width, $height, null);
    }

    /**
     * @throws \InvalidArgumentException when $refusal is empty
     */
    public static function rejected(string $mediaId, string $refusal): self
    {
        if ('' === trim($refusal)) {
            throw new \InvalidArgumentException('A rejected picture needs the reason it was refused.');
        }

        return new self($mediaId, MediaImageStatus::Rejected, null, null, null, $refusal);
    }
}
