<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The session metadata and attributes of a client. The test env keeps sessions in files (MockFileSessionStorage); PHP's time() stamps
 * their metadata, so a test moves a time back in the file instead of moving the clock forward.
 */
trait ReadsSessionMetadata
{
    private static function writeSessionCreatedTime(KernelBrowser $client, int $created): void
    {
        $file = self::sessionFileOf($client);
        $data = unserialize((string) file_get_contents($file));
        self::assertIsArray($data);
        self::assertIsArray($data['_sf2_meta'] ?? null);
        $data['_sf2_meta']['c'] = $created;
        file_put_contents($file, serialize($data));
    }

    private static function writeSessionAttribute(KernelBrowser $client, string $key, mixed $value): void
    {
        $file = self::sessionFileOf($client);
        $data = unserialize((string) file_get_contents($file));
        self::assertIsArray($data);
        self::assertIsArray($data['_sf2_attributes'] ?? null);
        $data['_sf2_attributes'][$key] = $value;
        file_put_contents($file, serialize($data));
    }

    /**
     * @return array{c: int, u: int, l: int}
     */
    private static function sessionMetadata(KernelBrowser $client): array
    {
        $data = unserialize((string) file_get_contents(self::sessionFileOf($client)));
        self::assertIsArray($data);
        /** @var array{c: int, u: int, l: int} $metadata */
        $metadata = $data['_sf2_meta'];

        return $metadata;
    }

    /**
     * @return mixed null when the session holds no such attribute
     */
    private static function sessionAttribute(KernelBrowser $client, string $key): mixed
    {
        $data = unserialize((string) file_get_contents(self::sessionFileOf($client)));
        self::assertIsArray($data);
        self::assertIsArray($data['_sf2_attributes'] ?? null);

        return $data['_sf2_attributes'][$key] ?? null;
    }

    private static function sessionFileOf(KernelBrowser $client): string
    {
        $cookie = $client->getCookieJar()->get('__Host-pinround', '/', 'localhost');
        self::assertNotNull($cookie);
        $file = self::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$cookie->getValue().'.mocksess';
        self::assertFileExists($file);

        return $file;
    }
}
