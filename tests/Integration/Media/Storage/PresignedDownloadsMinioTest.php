<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Storage;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaVariant;
use App\Media\Domain\MediaVariantKey;
use App\Media\Infrastructure\Storage\ObjectStorageFilesystemFactory;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedDownloads;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Fixtures\Media\ImageFixtures;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

/**
 * The signed URL expiry test of P05.T2, against the dev stack's MinIO. MinIO checks a URL against its own clock, so a
 * URL that must still work is signed at the real now. The URL is signed for OBJECT_STORAGE_ENDPOINT here, because the
 * browser-facing OBJECT_STORAGE_PUBLIC_URL (localhost) is not reachable from inside the app container.
 */
#[Group('object-storage')]
final class PresignedDownloadsMinioTest extends TestCase
{
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';

    /** @var list<string> */
    private array $storedKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->storedKeys as $key) {
            $this->storage()->delete($key);
        }
    }

    #[Test]
    public function aUrlFetchedWithinItsFiveMinutesGivesTheStoredWebpInlineWhateverTypeItWasStoredWith(): void
    {
        $webp = ImageFixtures::webpLossy(64, 36);
        $key = $this->storedView($webp, 'application/octet-stream');

        $response = HttpClient::create()->request('GET', $this->downloads()->forGet($key, new NativeClock()->now()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($webp, $response->getContent());
        self::assertSame('image/webp', $response->getHeaders()['content-type'][0] ?? null);
        self::assertSame('inline', $response->getHeaders()['content-disposition'][0] ?? null);
        self::assertSame('private, max-age=240, immutable', $response->getHeaders()['cache-control'][0] ?? null);
    }

    #[Test]
    public function aUrlSignedMoreThanFiveMinutesAgoIsRefusedAndGivesNoByte(): void
    {
        $webp = ImageFixtures::webpLossy(64, 36);
        $key = $this->storedView($webp, 'image/webp');
        $sixMinutesAgo = new NativeClock()->now()->sub(new \DateInterval('PT6M'));

        $response = HttpClient::create()->request('GET', $this->downloads()->forGet($key, $sixMinutesAgo));

        self::assertSame(403, $response->getStatusCode());
        $refusal = $response->getContent(false);
        self::assertStringContainsString('<Code>AccessDenied</Code>', $refusal);
        self::assertStringContainsString('<Message>Request has expired</Message>', $refusal);
        self::assertStringNotContainsString($webp, $refusal);
    }

    #[Test]
    public function aUrlWhoseResponseTypeWasChangedIsRefused(): void
    {
        $key = $this->storedView(ImageFixtures::webpLossy(64, 36), 'image/webp');
        $url = $this->downloads()->forGet($key, new NativeClock()->now());

        $response = HttpClient::create()->request('GET', str_replace('response-content-type=image%2Fwebp', 'response-content-type=text%2Fhtml', $url));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('SignatureDoesNotMatch', $response->getContent(false));
    }

    private function storedView(string $bytes, string $storedType): string
    {
        $key = MediaVariantKey::of(new OrganizationId(self::ORGANIZATION), new MediaId(Uuid::v7()->toRfc4122()), new \DateTimeImmutable('2026-04-07 09:00:00 UTC'), MediaVariant::View)->toString();
        $this->storage()->write($key, $bytes, ['mimetype' => $storedType]);
        $this->storedKeys[] = $key;

        return $key;
    }

    private function downloads(): PresignedDownloads
    {
        $settings = $this->settings();

        return new PresignedDownloads(
            new ObjectStorageSettings($settings->endpoint, $settings->endpoint, $settings->bucket, $settings->region, $settings->accessKeyId, $settings->secretAccessKey),
        );
    }

    private function settings(): ObjectStorageSettings
    {
        return new ObjectStorageSettings(
            self::env('OBJECT_STORAGE_ENDPOINT'),
            self::env('OBJECT_STORAGE_PUBLIC_URL'),
            self::env('OBJECT_STORAGE_BUCKET'),
            self::env('OBJECT_STORAGE_REGION'),
            self::env('OBJECT_STORAGE_ACCESS_KEY_ID'),
            self::env('OBJECT_STORAGE_SECRET_ACCESS_KEY'),
        );
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? null;

        return \is_string($value) ? $value : throw new \LogicException($name.' is not set in .env.test.');
    }

    private function storage(): FilesystemOperator
    {
        return new ObjectStorageFilesystemFactory($this->settings())->create();
    }
}
