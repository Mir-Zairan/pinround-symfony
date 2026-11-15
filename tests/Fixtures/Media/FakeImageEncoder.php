<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Media;

use App\Media\Application\Processing\EncodedImage;
use App\Media\Application\Processing\EncodedVariant;
use App\Media\Application\Processing\ImageEncoder;
use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\UndecodableImage;
use App\Media\Domain\UploadPolicy;

/**
 * ImageEncoder as the contract states it, for a box without libvips: it decodes only what ImageFixtures builds (each
 * format's structure is read on its own, not by the header reader under test), refuses what does not decode fully as
 * the stated format or has more pixels than UploadPolicy::MAX_PIXELS, and answers two lossless WebPs of the
 * contract's sizes: scaled down to the maximum width, never up, aspect ratio kept, and holding 32 hash bytes of the
 * original per variant instead of any byte of it.
 */
final class FakeImageEncoder implements ImageEncoder
{
    /** How often encode() was called, refused calls included. */
    public int $encodeCount = 0;

    public function encode(string $original, ImageFormat $format): EncodedImage
    {
        ++$this->encodeCount;

        [$width, $height] = match ($format) {
            ImageFormat::Png => self::decodePng($original),
            ImageFormat::Jpeg => self::decodeJpeg($original),
            ImageFormat::Webp => self::decodeWebp($original),
        };

        return new EncodedImage(
            self::variant($original, 'view', $width, $height, self::VIEW_MAX_WIDTH),
            self::variant($original, 'thumb', $width, $height, self::THUMB_MAX_WIDTH),
        );
    }

    private static function variant(string $original, string $name, int $width, int $height, int $maxWidth): EncodedVariant
    {
        $scaledWidth = min($width, $maxWidth);
        $scaledHeight = max(1, (int) round($height * $scaledWidth / $width));

        return new EncodedVariant(
            ImageFixtures::webpLossless($scaledWidth, $scaledHeight, hash('sha256', $name.'|'.$original, true)),
            $scaledWidth,
            $scaledHeight,
        );
    }

    /**
     * @return array{int, int}
     */
    private static function decodePng(string $bytes): array
    {
        if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new UndecodableImage();
        }
        $offset = 8;
        $width = $height = 0;
        $pixelData = '';
        while (true) {
            $header = substr($bytes, $offset, 8);
            if (8 !== \strlen($header)) {
                throw new UndecodableImage();
            }
            $unpacked = unpack('Nlength/a4type', $header);
            if (false === $unpacked) {
                throw new UndecodableImage();
            }
            $length = $unpacked['length'];
            $type = $unpacked['type'];
            $data = substr($bytes, $offset + 8, $length);
            $crc = substr($bytes, $offset + 8 + $length, 4);
            if (\strlen($data) !== $length || 4 !== \strlen($crc) || pack('N', crc32($type.$data)) !== $crc) {
                throw new UndecodableImage();
            }
            $offset += 12 + $length;

            if ('IHDR' === $type) {
                if (13 !== $length) {
                    throw new UndecodableImage();
                }
                $ihdr = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                if (false === $ihdr || [8, 0, 0, 0, 0] !== [$ihdr['depth'], $ihdr['color'], $ihdr['compression'], $ihdr['filter'], $ihdr['interlace']]) {
                    throw new UndecodableImage();
                }
                $width = $ihdr['width'];
                $height = $ihdr['height'];
                if ($width < 1 || $height < 1 || $width * $height > UploadPolicy::MAX_PIXELS) {
                    throw new UndecodableImage();
                }
            } elseif ('IDAT' === $type) {
                $pixelData .= $data;
            } elseif ('IEND' === $type) {
                break;
            }
        }

        $pixels = 0 === $width ? false : @gzuncompress($pixelData);
        if (false === $pixels || \strlen($pixels) !== $height * ($width + 1)) {
            throw new UndecodableImage();
        }

        return [$width, $height];
    }

    /**
     * @return array{int, int}
     */
    private static function decodeJpeg(string $bytes): array
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            throw new UndecodableImage();
        }
        $offset = 2;
        $size = null;
        while ($offset + 4 <= \strlen($bytes) && "\xFF" === $bytes[$offset]) {
            $marker = \ord($bytes[$offset + 1]);
            $segment = unpack('nlength', $bytes, $offset + 2);
            if (false === $segment || $segment['length'] < 2) {
                throw new UndecodableImage();
            }
            if (0xC0 === $marker) {
                $frame = unpack('nheight/nwidth', $bytes, $offset + 5);
                if (false === $frame) {
                    throw new UndecodableImage();
                }
                $size = [$frame['width'], $frame['height']];
            }
            if (0xDA === $marker) {
                $endOfImage = strpos($bytes, "\xFF\xD9", $offset + 2 + $segment['length']);
                if (null === $size || false === $endOfImage || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > UploadPolicy::MAX_PIXELS) {
                    throw new UndecodableImage();
                }

                return $size;
            }
            $offset += 2 + $segment['length'];
        }

        throw new UndecodableImage();
    }

    /**
     * @return array{int, int}
     */
    private static function decodeWebp(string $bytes): array
    {
        $riff = unpack('a4magic/Vsize/a4form', $bytes);
        if (false === $riff || 'RIFF' !== $riff['magic'] || 'WEBP' !== $riff['form'] || 8 + $riff['size'] !== \strlen($bytes)) {
            throw new UndecodableImage();
        }
        $offset = 12;
        $size = null;
        while ($offset + 8 <= \strlen($bytes)) {
            $chunk = unpack('a4type/Vlength', $bytes, $offset);
            if (false === $chunk || $offset + 8 + $chunk['length'] > \strlen($bytes)) {
                throw new UndecodableImage();
            }
            $data = substr($bytes, $offset + 8, $chunk['length']);
            if (null === $size && 'VP8L' === $chunk['type'] && $chunk['length'] >= 5) {
                $bits = unpack('V', $data, 1);
                $size = false === $bits ? null : [($bits[1] & 0x3FFF) + 1, (($bits[1] >> 14) & 0x3FFF) + 1];
            } elseif (null === $size && 'VP8 ' === $chunk['type'] && $chunk['length'] >= 10) {
                $dimensions = unpack('vwidth/vheight', $data, 6);
                $size = false === $dimensions ? null : [$dimensions['width'] & 0x3FFF, $dimensions['height'] & 0x3FFF];
            }
            $offset += 8 + $chunk['length'] + ($chunk['length'] & 1);
        }
        if (null === $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > UploadPolicy::MAX_PIXELS) {
            throw new UndecodableImage();
        }

        return $size;
    }
}
