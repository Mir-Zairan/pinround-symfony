<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Storage;

use App\Media\Domain\UploadIntentId;
use App\Media\Infrastructure\Storage\UploadKey;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadKeyTest extends TestCase
{
    private const string ORGANIZATION = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01';
    private const string INTENT = '0192f3a0-aaaa-7bbb-8ccc-ddddeeeeffff';

    #[Test]
    public function itBuildsUploadsSlashOrganizationSlashIntent(): void
    {
        $key = UploadKey::forIntent(new OrganizationId(self::ORGANIZATION), new UploadIntentId(self::INTENT));

        self::assertSame('uploads/'.self::ORGANIZATION.'/'.self::INTENT, $key->toString());
    }

    #[Test]
    public function itWritesBothIdsInLowercaseWhateverCaseTheyWereGiven(): void
    {
        $key = UploadKey::forIntent(
            new OrganizationId(strtoupper(self::ORGANIZATION)),
            new UploadIntentId(strtoupper(self::INTENT)),
        );

        self::assertSame('uploads/'.self::ORGANIZATION.'/'.self::INTENT, $key->toString());
    }

    #[Test]
    public function differentIntentsOfOneOrganizationGetDifferentKeys(): void
    {
        $organizationId = new OrganizationId(self::ORGANIZATION);

        $first = UploadKey::forIntent($organizationId, new UploadIntentId(self::INTENT));
        $second = UploadKey::forIntent($organizationId, new UploadIntentId('0192f3a0-aaaa-7bbb-8ccc-ddddeeee0000'));

        self::assertNotSame($first->toString(), $second->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function organizationIdsThatAreNotUuids(): iterable
    {
        yield 'a path traversal' => ['../../other-org'];
        yield 'a slash inside' => ['0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01/x'];
        yield 'a trailing newline' => ["0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f01\n"];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('organizationIdsThatAreNotUuids')]
    public function noUserTextReachesTheKeyThroughTheOrganizationId(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical UUIDv7');

        UploadKey::forIntent(new OrganizationId($text), new UploadIntentId(self::INTENT));
    }
}
