<?php

declare(strict_types=1);

namespace App\Review\UI;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\Review\Api\PlacedPin;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\OrganizationId;
use Psr\Clock\ClockInterface;

/**
 * The pictures a person keeps for themself, a report draft's or a notebook's, as the Screenshot component draws them:
 * every picture's signed URL in one call, its pins numbered from 1 in their order. Holds no state (ADR-0008).
 */
final readonly class ScreenshotWithPinsViews
{
    private const string VARIANT = 'view';

    public function __construct(private SignedUrls $signedUrls, private ClockInterface $clock)
    {
    }

    /**
     * @param list<ScreenshotWithPins> $screenshots   at most SignedUrls::MAX_MEDIA_IDS, in the request's transaction whose
     *                                                tenant is $organizationId
     * @param string                   $domIdPrefix   picture n's dom id is "<prefix>-<n>", from 1
     * @param string                   $altTextFormat the picture's alt text, with its number from 1 for %d
     *
     * @return list<ScreenshotView> in the order of $screenshots; a picture not ready shows as processing or rejected
     */
    public function viewsOf(OrganizationId $organizationId, array $screenshots, string $domIdPrefix, string $altTextFormat): array
    {
        $images = $this->signedUrls->forVariants(
            $organizationId,
            array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $screenshots),
            self::VARIANT,
            $this->clock->now(),
        );
        $views = [];
        foreach ($screenshots as $index => $screenshot) {
            $image = $images[$screenshot->mediaId] ?? null;
            $isReady = null !== $image && MediaImageStatus::Ready === $image->status;
            $views[] = new ScreenshotView(
                \sprintf('%s-%d', $domIdPrefix, $index + 1),
                $isReady ? $image->url : null,
                $isReady ? $image->width : null,
                $isReady ? $image->height : null,
                \sprintf($altTextFormat, $index + 1),
                $screenshot->title,
                $screenshot->summary,
                array_map(static fn (int $number, PlacedPin $pin): PinView => new PinView($number + 1, $pin->xPercent, $pin->yPercent, $pin->note), array_keys($screenshot->pins), $screenshot->pins),
                $image->status ?? MediaImageStatus::Pending,
            );
        }

        return $views;
    }
}
