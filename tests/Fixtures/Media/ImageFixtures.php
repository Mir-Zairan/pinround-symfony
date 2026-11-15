<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Media;

/**
 * Builds small pictures byte by byte, so no binary file from outside is in the repository. They are grey (PNG) or empty
 * (JPEG, WebP) pictures: only their structure and the size in their header are real, which is all the header reader
 * and FakeImageEncoder look at.
 */
final class ImageFixtures
{
    public const string SCRIPT_BYTES = '<?php system($_GET["cmd"]); ?>';

    private function __construct()
    {
    }

    /**
     * A decodable 8-bit grey PNG; its pixel data is $width × $height zero bytes.
     */
    public static function png(int $width, int $height): string
    {
        return self::pngSignature()
            .self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .self::pngChunk('IDAT', gzcompress(str_repeat("\x00", $height * ($width + 1))) ?: '')
            .self::pngChunk('IEND', '');
    }

    /**
     * A PNG whose header states $width × $height but whose image data is one empty row: read from its header it is as
     * big as stated, and no decoder could decode it as that.
     */
    public static function pngClaiming(int $width, int $height): string
    {
        return self::pngSignature()
            .self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .self::pngChunk('IDAT', gzcompress("\x00") ?: '')
            .self::pngChunk('IEND', '');
    }

    /**
     * An APNG: an acTL chunk before the first IDAT announces two frames. Its pixel data is one empty row, so it does not
     * decode as the size it states.
     */
    public static function animatedPng(int $width, int $height): string
    {
        $frameControl = pack('NNNNNnnCC', 0, $width, $height, 0, 0, 1, 10, 0, 0);

        return self::pngSignature()
            .self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .self::pngChunk('acTL', pack('NN', 2, 0))
            .self::pngChunk('fcTL', $frameControl)
            .self::pngChunk('IDAT', gzcompress("\x00") ?: '')
            .self::pngChunk('fcTL', pack('N', 1).substr($frameControl, 4))
            .self::pngChunk('fdAT', pack('N', 2).(gzcompress("\x00") ?: ''))
            .self::pngChunk('IEND', '');
    }

    /**
     * A baseline JPEG with one component: SOI, JFIF, frame header, scan header, a little entropy data and EOI.
     */
    public static function jpeg(int $width, int $height): string
    {
        return "\xFF\xD8"
            ."\xFF\xE0".pack('n', 16)."JFIF\x00\x01\x01\x00".pack('nn', 1, 1)."\x00\x00"
            ."\xFF\xC0".pack('n', 11)."\x08".pack('nn', $height, $width)."\x01\x01\x11\x00"
            ."\xFF\xDA".pack('n', 8)."\x01\x01\x00\x00\x3F\x00"
            ."\x12\x34\x56"
            ."\xFF\xD9";
    }

    /**
     * A lossy WebP (a VP8 key frame); $payload follows the frame header.
     */
    public static function webpLossy(int $width, int $height, string $payload = "\x00\x00\x00\x00"): string
    {
        $frame = "\x10\x00\x00\x9D\x01\x2A".pack('vv', $width, $height).$payload;

        return self::riff(self::riffChunk('VP8 ', $frame));
    }

    /**
     * A lossless WebP (a VP8L bitstream); $payload follows its 5-byte header.
     */
    public static function webpLossless(int $width, int $height, string $payload = "\x00\x00\x00\x00"): string
    {
        return self::riff(self::losslessChunk($width, $height, $payload));
    }

    /**
     * An extended WebP (VP8X) of a canvas $width × $height. $animationFlag sets the flag byte's Animation bit;
     * $animationChunks puts ANIM and ANMF chunks where the bitstream would be. Neither: a still picture.
     */
    public static function webpExtended(int $width, int $height, bool $animationFlag = false, bool $animationChunks = false): string
    {
        $flags = $animationFlag ? 0x02 : 0x00;
        $canvas = pack('C', $flags)."\x00\x00\x00".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3);
        $frames = $animationChunks
            ? self::riffChunk('ANIM', "\x00\x00\x00\x00\x00\x00").self::riffChunk('ANMF', str_repeat("\x00", 16))
            : self::losslessChunk($width, $height, "\x00\x00\x00\x00");

        return self::riff(self::riffChunk('VP8X', $canvas).$frames);
    }

    /**
     * What a polyglot looks like: a picture with $tail after its last chunk.
     */
    public static function withAppended(string $image, string $tail = self::SCRIPT_BYTES): string
    {
        return $image.$tail;
    }

    private static function pngSignature(): string
    {
        return "\x89PNG\r\n\x1a\n";
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function losslessChunk(int $width, int $height, string $payload): string
    {
        return self::riffChunk('VP8L', "\x2F".pack('V', ($width - 1) | (($height - 1) << 14)).$payload);
    }

    private static function riffChunk(string $type, string $data): string
    {
        return $type.pack('V', \strlen($data)).$data.(1 === \strlen($data) % 2 ? "\x00" : '');
    }

    private static function riff(string $chunks): string
    {
        return 'RIFF'.pack('V', 4 + \strlen($chunks)).'WEBP'.$chunks;
    }
}
