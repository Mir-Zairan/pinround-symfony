<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Infrastructure\Demo;

use App\Media\Application\Processing\ReadyVariants;
use App\Media\Infrastructure\Demo\SeedPictureFile;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\Media\Infrastructure\Processing\FlysystemMediaFiles;
use App\Media\Infrastructure\Processing\PurePhpImageHeaderReader;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Fixtures\Media\FakeImageEncoder;
use App\Tests\Integration\Media\Persistence\MediaRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SeedPictureFile on the med_object table (as pinround_app, under row-level security) and the dev stack's MinIO, with
 * the FakeImageEncoder in place of libvips, as the dev demo seed runs it on the showcase screenshots.
 */
#[Group('object-storage')]
final class SeedPictureFileTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e2101';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e2103';
    private const string SHOWCASE_PNG = __DIR__.'/../../../../../src/Review/Infrastructure/Demo/showcase/search-bread-finds-nothing.png';
    private const string UPLOADED_AT = '2026-03-02 09:58:00 UTC';

    private FakeImageEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new FakeImageEncoder();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->cleanUp();
        parent::tearDown();
    }

    #[Test]
    public function aShowcasePngEndsReadyWithItsViewAndThumbWrittenToTheBucket(): void
    {
        $mediaId = $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->seedPictureFile()->addFromPng(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), self::SHOWCASE_PNG, new \DateTimeImmutable(self::UPLOADED_AT)));

        $viewKey = 'media/'.self::ACME_FOODS.'/2026/03/'.$mediaId.'/view.webp';
        $thumbKey = 'media/'.self::ACME_FOODS.'/2026/03/'.$mediaId.'/thumb.webp';
        $original = file_get_contents(self::SHOWCASE_PNG);
        self::assertIsString($original);
        self::assertSame(
            ['ready', hash('sha256', $original), 1024, 640, \strlen($original), $viewKey, $thumbKey],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchNumeric("SELECT status, sha256, width, height, bytes, variants->>'view', variants->>'thumb' FROM med_object WHERE id = ?", [$mediaId])),
        );
        self::assertStringStartsWith('RIFF', $this->storage()->read($viewKey));
        self::assertStringStartsWith('RIFF', $this->storage()->read($thumbKey));
        self::assertSame(1, $this->encoder->encodeCount);
    }

    #[Test]
    public function theSameFileAgainLendsTheVariantsOfTheFirstWithoutEncodingAgain(): void
    {
        [$firstId, $secondId] = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            $this->seedPictureFile()->addFromPng(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), self::SHOWCASE_PNG, new \DateTimeImmutable(self::UPLOADED_AT)),
            $this->seedPictureFile()->addFromPng(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), self::SHOWCASE_PNG, new \DateTimeImmutable(self::UPLOADED_AT)),
        ]);

        $variants = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllKeyValue('SELECT id, variants::text FROM med_object ORDER BY id'));
        self::assertNotSame($firstId, $secondId);
        self::assertSame([$firstId, $secondId], array_keys($variants));
        self::assertSame($variants[$firstId], $variants[$secondId]);
        self::assertSame(1, $this->encoder->encodeCount);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableFiles(): iterable
    {
        yield 'no file' => [__DIR__.'/no-such-picture.png', 'The picture no-such-picture.png is no readable file of 1 byte to 15 MB.'];
        yield 'not a picture' => [__FILE__, 'The picture SeedPictureFileTest.php is no readable PNG.'];
    }

    #[Test]
    #[DataProvider('unusableFiles')]
    public function aFileThatIsNoPngIsRefusedWithNothingStored(string $path, string $reason): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->seedPictureFile()->addFromPng(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $path, new \DateTimeImmutable(self::UPLOADED_AT)));
            self::fail('A file that is no PNG was taken.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame($reason, $refused->getMessage());
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM med_object')));
        self::assertSame([], $this->storage()->listContents('media/'.self::ACME_FOODS, true)->toArray());
        self::assertSame(0, $this->encoder->encodeCount);
    }

    private function seedPictureFile(): SeedPictureFile
    {
        $mediaObjects = new DoctrineMediaObjects(self::getContainer()->get('doctrine.orm.default_entity_manager'));
        $headerReader = new PurePhpImageHeaderReader();

        return new SeedPictureFile($mediaObjects, new ReadyVariants($mediaObjects, new FlysystemMediaFiles($this->storage()), $headerReader, $this->encoder), $headerReader);
    }

    private function cleanUp(): void
    {
        new MediaRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class))->removeAllOf(self::ACME_FOODS);
        $this->storage()->deleteDirectory('media/'.self::ACME_FOODS);
    }

    private function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get('test.media_object_storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
