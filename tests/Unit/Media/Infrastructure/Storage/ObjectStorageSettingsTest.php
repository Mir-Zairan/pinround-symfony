<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Infrastructure\Storage\ObjectStorageNotConfigured;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObjectStorageSettingsTest extends TestCase
{
    #[Test]
    public function itKeepsCompleteSettings(): void
    {
        $settings = self::settings();

        self::assertSame('http://minio:9000', $settings->endpoint);
        self::assertSame('https://files.example.test', $settings->publicUrl);
        self::assertSame('pinround', $settings->bucket);
        self::assertSame('us-east-1', $settings->region);
        self::assertSame('acme-key', $settings->accessKeyId);
        self::assertSame('acme-secret', $settings->secretAccessKey);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entriesThatMustBeSet(): iterable
    {
        foreach (['endpoint', 'publicUrl', 'bucket', 'region', 'accessKeyId', 'secretAccessKey'] as $argument) {
            $variable = 'OBJECT_STORAGE_'.strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $argument) ?? '');

            yield $argument.' empty' => [$argument, '', $variable];
            yield $argument.' blank' => [$argument, " \t\n", $variable];
        }
    }

    #[Test]
    #[DataProvider('entriesThatMustBeSet')]
    public function itRefusesAnEmptyOrBlankEntryNamingIt(string $argument, string $value, string $variable): void
    {
        try {
            self::settings([$argument => $value]);
            self::fail('Settings with a blank '.$argument.' were accepted.');
        } catch (ObjectStorageNotConfigured $refusal) {
            self::assertSame($variable.' is not set: object storage refuses to start without it.', $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urlsThatAreNotHttp(): iterable
    {
        foreach (['endpoint' => 'OBJECT_STORAGE_ENDPOINT', 'publicUrl' => 'OBJECT_STORAGE_PUBLIC_URL'] as $argument => $variable) {
            yield $argument.' without scheme' => [$argument, 'minio:9000', $variable];
            yield $argument.' ftp' => [$argument, 'ftp://minio', $variable];
            yield $argument.' without host' => [$argument, 'http://', $variable];
        }
    }

    #[Test]
    #[DataProvider('urlsThatAreNotHttp')]
    public function itRefusesAUrlThatIsNotHttpWithAHost(string $argument, string $value, string $variable): void
    {
        try {
            self::settings([$argument => $value]);
            self::fail('Settings with the URL "'.$value.'" were accepted.');
        } catch (ObjectStorageNotConfigured $refusal) {
            self::assertSame($variable.' is not an http(s) URL with a host.', $refusal->getMessage());
        }
    }

    #[Test]
    public function itAcceptsHttpAndHttpsInAnyCase(): void
    {
        $settings = self::settings(['endpoint' => 'HTTP://minio:9000', 'publicUrl' => 'HTTPS://files.example.test']);

        self::assertSame('HTTP://minio:9000', $settings->endpoint);
    }

    /**
     * @param array<string, string> $overrides
     */
    private static function settings(array $overrides = []): ObjectStorageSettings
    {
        return new ObjectStorageSettings(...[
            'endpoint' => 'http://minio:9000',
            'publicUrl' => 'https://files.example.test',
            'bucket' => 'pinround',
            'region' => 'us-east-1',
            'accessKeyId' => 'acme-key',
            'secretAccessKey' => 'acme-secret',
            ...$overrides,
        ]);
    }
}
