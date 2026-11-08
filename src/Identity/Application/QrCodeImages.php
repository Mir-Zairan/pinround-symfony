<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * QR codes drawn on the server, so the seed in them never goes to another service.
 */
interface QrCodeImages
{
    /**
     * @return string an SVG document, 200 by 200 pixels
     */
    public function svgOf(#[\SensitiveParameter] string $content): string;
}
