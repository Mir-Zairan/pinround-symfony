<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Storage;

use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadKey;
use App\Media\Infrastructure\Storage\PresignedUploads;
use App\SharedKernel\Domain\OrganizationId;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

/**
 * The bucket and the upload signer as the container builds them from the OBJECT_STORAGE_* values of .env.test,
 * against the dev stack's MinIO. The signed URL names OBJECT_STORAGE_PUBLIC_URL (localhost:9000), which is not
 * reachable from inside the app container, so the PUT resolves that host to OBJECT_STORAGE_ENDPOINT's address on
 * the same port; the Host header MinIO checks the signature against stays the signed one.
 */
#[Group('object-storage')]
final class ObjectStorageWiringTest extends KernelTestCase
{
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';

    private const string PNG_BODY = "\x89PNG\r\n\x1a\nwired, not really a picture";

    private ?UploadKey $uploadedKey = null;

    protected function tearDown(): void
    {
        if (null !== $this->uploadedKey) {
            self::storage()->delete($this->uploadedKey->toString());
        }
        parent::tearDown();
    }

    #[Test]
    public function theWiredSignerSignsForThePublicUrlWithTheConfiguredKeyAndRegion(): void
    {
        $put = self::presignedUploads()->forPut($this->newKey(), 'image/png', \strlen(self::PNG_BODY));

        self::assertStringStartsWith(self::env('OBJECT_STORAGE_PUBLIC_URL').'/'.self::env('OBJECT_STORAGE_BUCKET').'/uploads/', $put->url);
        parse_str((string) parse_url($put->url, \PHP_URL_QUERY), $query);
        self::assertIsString($query['X-Amz-Credential'] ?? null);
        self::assertMatchesRegularExpression(
            '~\A'.preg_quote(self::env('OBJECT_STORAGE_ACCESS_KEY_ID'), '~').'/\d{8}/'.preg_quote(self::env('OBJECT_STORAGE_REGION'), '~').'/s3/aws4_request\z~',
            $query['X-Amz-Credential'],
        );
    }

    #[Test]
    public function aPutThroughTheWiredSignerCanBeReadBackThroughTheWiredBucket(): void
    {
        $key = $this->newKey();
        $put = self::presignedUploads()->forPut($key, 'image/png', \strlen(self::PNG_BODY));
        $publicHost = (string) parse_url($put->url, \PHP_URL_HOST);
        $storageAddress = gethostbyname((string) parse_url(self::env('OBJECT_STORAGE_ENDPOINT'), \PHP_URL_HOST));

        $response = HttpClient::create()->request('PUT', $put->url, [
            'headers' => $put->headers,
            'body' => self::PNG_BODY,
            'resolve' => [$publicHost => $storageAddress],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(self::PNG_BODY, self::storage()->read($key->toString()));
        self::assertSame('image/png', self::storage()->mimeType($key->toString()));
    }

    private function newKey(): UploadKey
    {
        return $this->uploadedKey = UploadKey::forIntent(new OrganizationId(self::ORGANIZATION), new UploadIntentId(Uuid::v7()->toRfc4122()));
    }

    private static function presignedUploads(): PresignedUploads
    {
        $presignedUploads = self::getContainer()->get('test.media_presigned_uploads');
        \assert($presignedUploads instanceof PresignedUploads);

        return $presignedUploads;
    }

    private static function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get('test.media_object_storage');
        \assert($storage instanceof FilesystemOperator);

        return $storage;
    }

    private static function env(string $name): string
    {
        $value = $_SERVER[$name] ?? null;

        return \is_string($value) && '' !== $value ? $value : throw new \LogicException($name.' is not set in .env.test.');
    }
}
