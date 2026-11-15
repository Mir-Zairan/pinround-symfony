<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Processing;

use App\Media\Application\Processing\ImageFormat;
use App\Media\Application\Processing\ImageHeader;
use App\Media\Application\Processing\ImageHeaderReader;
use App\Media\Application\Processing\UnknownImageFormat;
use App\Media\Application\Processing\UnreadableImageHeader;

/**
 * Reads PNG, JPEG and WebP headers with string functions only, so no image library parses an untrusted file before its
 * size and animation are known. Every offset is checked against the bytes given before it is read; a header that runs
 * past them is unreadable, never guessed.
 */
final readonly class PurePhpImageHeaderReader implements ImageHeaderReader
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const string JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /** A PNG chunk's length field is a 31-bit number (PNG spec, 5.3). */
    private const int PNG_MAX_CHUNK_LENGTH = 0x7FFFFFFF;

    /** The start code of a lossy WebP key frame (RFC 9649, 19.1). */
    private const string VP8_START_CODE = "\x9D\x01\x2A";

    /** The first byte of a lossless WebP bitstream (RFC 9649, 3.2). */
    private const string VP8L_SIGNATURE = "\x2F";

    /** The Animation bit of the VP8X flags byte (RFC 9649, 2.7). */
    private const int VP8X_ANIMATION_FLAG = 0x02;

    public function read(string $leadingBytes): ImageHeader
    {
        if (str_starts_with($leadingBytes, self::PNG_SIGNATURE)) {
            return $this->readPng($leadingBytes);
        }
        if (str_starts_with($leadingBytes, self::JPEG_SIGNATURE)) {
            return $this->readJpeg($leadingBytes);
        }
        if (str_starts_with($leadingBytes, 'RIFF') && 'WEBP' === substr($leadingBytes, 8, 4)) {
            return $this->readWebp($leadingBytes);
        }

        throw new UnknownImageFormat();
    }

    /**
     * IHDR must be the first chunk. An APNG announces itself with an acTL chunk, which must come before the first IDAT,
     * so the chunks are walked up to that IDAT.
     */
    private function readPng(string $bytes): ImageHeader
    {
        if ('IHDR' !== substr($bytes, 12, 4) || 13 !== self::uint32BigEndian($bytes, 8, 'PNG')) {
            throw new UnreadableImageHeader('The PNG does not start with its IHDR chunk.');
        }
        $width = self::uint32BigEndian($bytes, 16, 'PNG');
        $height = self::uint32BigEndian($bytes, 20, 'PNG');

        $isAnimated = false;
        $offset = \strlen(self::PNG_SIGNATURE);
        while (true) {
            $chunkLength = self::uint32BigEndian($bytes, $offset, 'PNG');
            if ($chunkLength > self::PNG_MAX_CHUNK_LENGTH || $offset + 8 > \strlen($bytes)) {
                throw new UnreadableImageHeader('The PNG chunks before the image data are broken or run past the bytes read.');
            }
            $chunkType = substr($bytes, $offset + 4, 4);
            if ('IDAT' === $chunkType) {
                break;
            }
            if ('acTL' === $chunkType) {
                $isAnimated = true;
            }
            if ('IEND' === $chunkType) {
                throw new UnreadableImageHeader('The PNG ends before any image data.');
            }
            // Length, type and CRC around the data.
            $offset += 12 + $chunkLength;
        }

        return self::header(ImageFormat::Png, $width, $height, $isAnimated);
    }

    /**
     * The size is in the first start-of-frame segment; the segments before it (APPn, DQT, DHT…) are skipped by their
     * length. A scan or the end of the image before a frame header makes the file unreadable.
     */
    private function readJpeg(string $bytes): ImageHeader
    {
        $length = \strlen($bytes);
        $offset = 2;
        while ($offset < $length) {
            if ("\xFF" !== $bytes[$offset]) {
                throw new UnreadableImageHeader('The JPEG has bytes between its segments.');
            }
            // A marker may be preceded by any number of fill bytes 0xFF.
            while ($offset < $length && "\xFF" === $bytes[$offset]) {
                ++$offset;
            }
            if ($offset >= $length) {
                break;
            }
            $marker = \ord($bytes[$offset]);
            ++$offset;

            // TEM and RST0–RST7 stand alone, without a length.
            if (0x01 === $marker || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if (0xD8 === $marker || 0xD9 === $marker || 0xDA === $marker) {
                throw new UnreadableImageHeader('The JPEG has no frame header before its image data.');
            }
            $segmentLength = self::uint16BigEndian($bytes, $offset, 'JPEG');
            if ($segmentLength < 2) {
                throw new UnreadableImageHeader('The JPEG has a segment shorter than its own length field.');
            }
            if (self::isStartOfFrame($marker)) {
                // Length (2), sample precision (1), then the height and the width (2 each).
                $height = self::uint16BigEndian($bytes, $offset + 3, 'JPEG');
                $width = self::uint16BigEndian($bytes, $offset + 5, 'JPEG');

                return self::header(ImageFormat::Jpeg, $width, $height, false);
            }
            $offset += $segmentLength;
        }

        throw new UnreadableImageHeader('The JPEG frame header is not within the bytes read.');
    }

    /**
     * SOF0–SOF15 without DHT (C4), JPG (C8) and DAC (CC), which share the range.
     */
    private static function isStartOfFrame(int $marker): bool
    {
        return $marker >= 0xC0 && $marker <= 0xCF && !\in_array($marker, [0xC4, 0xC8, 0xCC], true);
    }

    /**
     * The first chunk is VP8 (lossy), VP8L (lossless) or VP8X (extended). Only VP8X can animate: by its flag, or by an
     * ANIM or ANMF chunk before the bitstream, which are both checked.
     */
    private function readWebp(string $bytes): ImageHeader
    {
        $chunkType = substr($bytes, 12, 4);
        $chunkDataOffset = 20;

        if ('VP8 ' === $chunkType) {
            // A 3-byte frame tag, the start code, then 14-bit width and height, each with 2 scaling bits on top.
            if (self::VP8_START_CODE !== substr($bytes, $chunkDataOffset + 3, 3)) {
                throw new UnreadableImageHeader('The lossy WebP has no key frame start code.');
            }
            $width = self::uint16LittleEndian($bytes, $chunkDataOffset + 6) & 0x3FFF;
            $height = self::uint16LittleEndian($bytes, $chunkDataOffset + 8) & 0x3FFF;

            return self::header(ImageFormat::Webp, $width, $height, false);
        }

        if ('VP8L' === $chunkType) {
            if (self::VP8L_SIGNATURE !== substr($bytes, $chunkDataOffset, 1)) {
                throw new UnreadableImageHeader('The lossless WebP has no signature byte.');
            }
            // 14 bits of width minus one, then 14 bits of height minus one.
            $bits = self::uint32LittleEndian($bytes, $chunkDataOffset + 1);

            return self::header(ImageFormat::Webp, ($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1, false);
        }

        if ('VP8X' === $chunkType) {
            if (\strlen($bytes) < $chunkDataOffset + 10) {
                throw new UnreadableImageHeader('The extended WebP header runs past the bytes read.');
            }
            $flags = \ord($bytes[$chunkDataOffset]);
            // 24-bit canvas width and height, each minus one, after the flags and 3 reserved bytes.
            $width = self::uint24LittleEndian($bytes, $chunkDataOffset + 4) + 1;
            $height = self::uint24LittleEndian($bytes, $chunkDataOffset + 7) + 1;
            $isAnimated = 0 !== ($flags & self::VP8X_ANIMATION_FLAG) || $this->hasWebpAnimationChunk($bytes);

            return self::header(ImageFormat::Webp, $width, $height, $isAnimated);
        }

        throw new UnreadableImageHeader('The WebP starts with no VP8, VP8L or VP8X chunk.');
    }

    /**
     * Walks the chunks after VP8X up to the bitstream: a file without the flag but with animation chunks is refused
     * as animated too, as a decoder could still play it.
     */
    private function hasWebpAnimationChunk(string $bytes): bool
    {
        $offset = 12;
        while (true) {
            if ($offset + 8 > \strlen($bytes)) {
                throw new UnreadableImageHeader('The extended WebP chunks run past the bytes read.');
            }
            $chunkType = substr($bytes, $offset, 4);
            if ('ANIM' === $chunkType || 'ANMF' === $chunkType) {
                return true;
            }
            if ('VP8 ' === $chunkType || 'VP8L' === $chunkType) {
                return false;
            }
            $chunkSize = self::uint32LittleEndian($bytes, $offset + 4);
            // Type, size and the data, padded to an even length.
            $offset += 8 + $chunkSize + ($chunkSize & 1);
        }
    }

    private static function header(ImageFormat $format, int $width, int $height, bool $isAnimated): ImageHeader
    {
        if ($width < 1 || $height < 1) {
            throw new UnreadableImageHeader(\sprintf('The %s header states no width or height.', $format->name));
        }

        return new ImageHeader($format, $width, $height, $isAnimated);
    }

    private static function uint32BigEndian(string $bytes, int $offset, string $formatName): int
    {
        return self::unpacked('N', $bytes, $offset, 4, $formatName);
    }

    private static function uint16BigEndian(string $bytes, int $offset, string $formatName): int
    {
        return self::unpacked('n', $bytes, $offset, 2, $formatName);
    }

    private static function uint32LittleEndian(string $bytes, int $offset): int
    {
        return self::unpacked('V', $bytes, $offset, 4, 'WebP');
    }

    private static function uint16LittleEndian(string $bytes, int $offset): int
    {
        return self::unpacked('v', $bytes, $offset, 2, 'WebP');
    }

    private static function uint24LittleEndian(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 3 > \strlen($bytes)) {
            throw new UnreadableImageHeader('The WebP header runs past the bytes read.');
        }

        return self::unpacked('V', substr($bytes, $offset, 3)."\x00", 0, 4, 'WebP');
    }

    private static function unpacked(string $code, string $bytes, int $offset, int $byteCount, string $formatName): int
    {
        if ($offset < 0 || $offset + $byteCount > \strlen($bytes)) {
            throw new UnreadableImageHeader(\sprintf('The %s header runs past the bytes read.', $formatName));
        }
        $values = unpack($code, $bytes, $offset);
        if (false === $values || !\is_int($values[1] ?? null)) {
            throw new UnreadableImageHeader(\sprintf('The %s header could not be read.', $formatName));
        }

        return $values[1];
    }
}
