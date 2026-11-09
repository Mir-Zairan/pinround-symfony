<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\QrCodeImages;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QrCodeImages with bacon/bacon-qr-code's SVG back end, which needs only ext-xmlwriter (no Imagick, no GD).
 */
final readonly class BaconQrCodeImages implements QrCodeImages
{
    private const int SIZE_PIXELS = 200;

    public function svgOf(#[\SensitiveParameter] string $content): string
    {
        return new Writer(new ImageRenderer(new RendererStyle(self::SIZE_PIXELS), new SvgImageBackEnd()))->writeString($content);
    }
}
