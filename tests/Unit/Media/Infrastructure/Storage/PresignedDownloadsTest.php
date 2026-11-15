<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedDownloads;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PresignedDownloadsTest extends TestCase
{
    private const string VIEW_KEY = 'media/0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01/2026/04/0192f3a0-aaaa-7bbb-8ccc-ddddeeeeffff/view.webp';
    private const string SIGNED_AT = '2026-04-07 09:30:00 UTC';

    #[Test]
    public function theUrlPointsAtThePublicHostPathStyleWithTheKeyAndNamesTheConfiguredAccessKeyAndRegion(): void
    {
        $url = $this->downloads()->forGet(self::VIEW_KEY, new \DateTimeImmutable(self::SIGNED_AT));

        self::assertSame('https', parse_url($url, \PHP_URL_SCHEME));
        self::assertSame('files.example.test', parse_url($url, \PHP_URL_HOST));
        self::assertSame('/pinround/'.self::VIEW_KEY, parse_url($url, \PHP_URL_PATH));
        self::assertSame('acme-key/20260407/eu-west-1/s3/aws4_request', self::query($url)['X-Amz-Credential'] ?? null);
    }

    #[Test]
    public function theSignatureChangesWithTheKeyAndTheTime(): void
    {
        $signatureOf = fn (string $key, string $at): string => self::query($this->downloads()->forGet($key, new \DateTimeImmutable($at)))['X-Amz-Signature'] ?? '';

        self::assertSame($signatureOf(self::VIEW_KEY, self::SIGNED_AT), $signatureOf(self::VIEW_KEY, self::SIGNED_AT));
        self::assertNotSame($signatureOf(self::VIEW_KEY, self::SIGNED_AT), $signatureOf(str_replace('view', 'thumb', self::VIEW_KEY), self::SIGNED_AT));
        self::assertNotSame($signatureOf(self::VIEW_KEY, self::SIGNED_AT), $signatureOf(self::VIEW_KEY, '2026-04-07 09:30:01 UTC'));
    }

    #[Test]
    public function anEmptyKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A signed URL needs the storage key of what it shows.');

        $this->downloads()->forGet('', new \DateTimeImmutable(self::SIGNED_AT));
    }

    private function downloads(): PresignedDownloads
    {
        return new PresignedDownloads(new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'eu-west-1', 'acme-key', 'acme-secret'));
    }

    /**
     * @return array<int|string, string>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return array_map(strval(...), array_filter($query, is_string(...)));
    }
}
