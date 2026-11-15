<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadKey;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedUploads;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PresignedUploadsTest extends TestCase
{
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';
    private const string INTENT = '0192f3a0-aaaa-7bbb-8ccc-ddddeeeeffff';

    #[Test]
    public function theUrlPointsAtThePublicHostPathStyleWithTheUploadKey(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/png', 2048);

        self::assertSame('https', parse_url($put->url, \PHP_URL_SCHEME));
        self::assertSame('files.example.test', parse_url($put->url, \PHP_URL_HOST));
        self::assertSame('/pinround/uploads/'.self::ORGANIZATION.'/'.self::INTENT, parse_url($put->url, \PHP_URL_PATH));
    }

    #[Test]
    public function contentTypeAndContentLengthAreSignedHeaders(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/png', 2048);

        $signedHeaders = explode(';', self::query($put->url)['X-Amz-SignedHeaders']);
        self::assertEqualsCanonicalizing(['content-length', 'content-type', 'host'], $signedHeaders);
    }

    #[Test]
    public function itTellsTheClientTheExactHeadersToSend(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/png', 2048);

        self::assertSame(['Content-Type' => 'image/png', 'Content-Length' => '2048'], $put->headers);
    }

    #[Test]
    public function theSignatureChangesWithTheContentTypeAndWithTheLength(): void
    {
        $uploads = $this->uploads();

        $signatureOf = static fn (string $type, int $length): string => self::query($uploads->forPut(self::key(), $type, $length)->url)['X-Amz-Signature'];

        self::assertNotSame($signatureOf('image/png', 2048), $signatureOf('image/jpeg', 2048));
        self::assertNotSame($signatureOf('image/png', 2048), $signatureOf('image/png', 2049));
        self::assertSame($signatureOf('image/png', 2048), $signatureOf('image/png', 2048));
    }

    #[Test]
    public function theUrlIsValidForFiveMinutesFromTheClock(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/png', 2048);

        $query = self::query($put->url);
        self::assertSame('300', $query['X-Amz-Expires']);
        self::assertSame('20260310T090000Z', $query['X-Amz-Date']);
        self::assertSame('2026-03-10T09:05:00+00:00', $put->expiresAt->format('c'));
    }

    #[Test]
    public function theSigningCredentialNamesTheConfiguredAccessKeyAndRegion(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/png', 2048);

        self::assertSame('acme-key/20260310/eu-west-1/s3/aws4_request', self::query($put->url)['X-Amz-Credential']);
        self::assertStringNotContainsString('acme-secret', $put->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function contentTypesThatAreNotAcceptedPictures(): iterable
    {
        yield 'empty' => [''];
        yield 'no subtype' => ['image'];
        yield 'a GIF' => ['image/gif'];
        yield 'an SVG' => ['image/svg+xml'];
        yield 'HTML' => ['text/html'];
        yield 'upper case' => ['IMAGE/PNG'];
        yield 'with a parameter' => ['image/png; charset=utf-8'];
        yield 'with a trailing newline' => ["image/png\n"];
        yield 'with a trailing CRLF' => ["image/jpeg\r\n"];
        yield 'with a header injection' => ["image/png\r\nX-Evil: 1"];
        yield 'with a BOM' => ["\u{FEFF}image/png"];
        yield 'with a space' => ['image/ png'];
    }

    #[Test]
    #[DataProvider('contentTypesThatAreNotAcceptedPictures')]
    public function itRefusesAContentTypeThatIsNotAnAcceptedPicture(string $contentType): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An upload must be image/png, image/jpeg or image/webp, without parameters.');

        $this->uploads()->forPut(self::key(), $contentType, 2048);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedPictureTypes(): iterable
    {
        yield 'PNG' => ['image/png'];
        yield 'JPEG' => ['image/jpeg'];
        yield 'WebP' => ['image/webp'];
    }

    #[Test]
    #[DataProvider('acceptedPictureTypes')]
    public function itSignsEachAcceptedPictureType(string $contentType): void
    {
        $put = $this->uploads()->forPut(self::key(), $contentType, 2048);

        self::assertSame($contentType, $put->headers['Content-Type']);
    }

    #[Test]
    public function itRefusesASizeOverFifteenMegabytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An upload may be at most 15 MB (15728640 bytes).');

        $this->uploads()->forPut(self::key(), 'image/png', 15 * 1024 * 1024 + 1);
    }

    #[Test]
    public function itRefusesTheLargestIntegerAsASize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An upload may be at most 15 MB');

        $this->uploads()->forPut(self::key(), 'image/png', \PHP_INT_MAX);
    }

    #[Test]
    public function itAcceptsExactlyFifteenMegabytes(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/png', 15 * 1024 * 1024);

        self::assertSame('15728640', $put->headers['Content-Length']);
    }

    #[Test]
    #[DataProvider('sizesBelowOneByte')]
    public function itRefusesASizeBelowOneByte(int $contentLength): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one byte');

        $this->uploads()->forPut(self::key(), 'image/png', $contentLength);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizesBelowOneByte(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    public function itAcceptsOneByte(): void
    {
        $put = $this->uploads()->forPut(self::key(), 'image/webp', 1);

        self::assertSame('1', $put->headers['Content-Length']);
    }

    private function uploads(): PresignedUploads
    {
        return new PresignedUploads(
            new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'eu-west-1', 'acme-key', 'acme-secret'),
            new MockClock('2026-03-10 09:00:00 UTC'),
        );
    }

    private static function key(): UploadKey
    {
        return UploadKey::forIntent(new OrganizationId(self::ORGANIZATION), new UploadIntentId(self::INTENT));
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
