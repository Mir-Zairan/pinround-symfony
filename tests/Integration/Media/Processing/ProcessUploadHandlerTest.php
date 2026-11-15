<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Processing;

use App\Media\Api\Event\MediaEvent;
use App\Media\Api\Event\MediaReady;
use App\Media\Api\Event\MediaRejected;
use App\Media\Application\Processing\CleanUpload;
use App\Media\Application\ProcessUpload;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectReason;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\Media\Infrastructure\Messenger\MessengerUploadProcessingQueue;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Fixtures\Media\FakeImageEncoder;
use App\Tests\Fixtures\Media\ImageFixtures;
use App\Tests\Integration\Media\Persistence\MediaRows;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * ProcessUploadHandler end to end on the real outbox, relay, async_media transport, med_object table (as pinround_app,
 * under row-level security) and the dev stack's MinIO, with the FakeImageEncoder in place of libvips. The clock stands
 * at 2026-03-02 10:00 UTC.
 */
#[Group('object-storage')]
final class ProcessUploadHandlerTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1901';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1903';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000e1911';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000e1912';
    private const string NOW = '2026-03-02 10:00:00 UTC';

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
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
    public function aValidPictureEndsReadyWithItsTwoWebpVariantsInTheBucketNoUploadObjectAndOneMediaReadyInTheOutbox(): void
    {
        $original = ImageFixtures::withAppended(ImageFixtures::png(2400, 1200));
        $this->uploadAndQueue(self::FIRST_MEDIA, $original);

        $this->relayAndHandle();

        $viewKey = $this->variantKey(self::FIRST_MEDIA, 'view');
        $thumbKey = $this->variantKey(self::FIRST_MEDIA, 'thumb');
        self::assertSame(
            ['ready', null, hash('sha256', $original), 1920, 960, ['thumb' => $thumbKey, 'view' => $viewKey]],
            $this->row(self::FIRST_MEDIA),
        );
        self::assertFalse($this->storage()->fileExists($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame([$thumbKey, $viewKey], $this->bucketKeys());
        foreach ([$viewKey, $thumbKey] as $key) {
            self::assertSame('image/webp', $this->storage()->mimeType($key));
            $stored = $this->storage()->read($key);
            self::assertStringStartsWith('RIFF', $stored);
            self::assertStringNotContainsString(ImageFixtures::SCRIPT_BYTES, $stored);
        }
        self::assertEquals([new MediaReady(self::ACME_FOODS, self::FIRST_MEDIA, new \DateTimeImmutable(self::NOW))], $this->mediaEventsInTheOutbox());
        self::assertSame(1, $this->encoder()->encodeCount);
    }

    #[Test]
    public function aFileThatIsNotAPictureEndsRejectedWithItsReasonAndItsUploadDeletedAndOneMediaRejectedInTheOutbox(): void
    {
        $this->uploadAndQueue(self::FIRST_MEDIA, "GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'image/png');

        $this->relayAndHandle();

        self::assertSame(['rejected', MediaRejectReason::NotAnImage->value, null, null, null, null], $this->row(self::FIRST_MEDIA));
        self::assertFalse($this->storage()->fileExists($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame([], $this->bucketKeys());
        self::assertEquals([new MediaRejected(self::ACME_FOODS, self::FIRST_MEDIA, MediaRejectReason::NotAnImage->value, new \DateTimeImmutable(self::NOW))], $this->mediaEventsInTheOutbox());
        self::assertSame(0, $this->encoder()->encodeCount);
    }

    #[Test]
    public function anObjectLargerThanItsIntentIsDeletedAndRejectedAndNothingIsStored(): void
    {
        $png = ImageFixtures::png(8, 8);
        $this->uploadAndQueue(self::FIRST_MEDIA, $png.str_repeat('x', 50), 'image/png', \strlen($png));

        $this->relayAndHandle();

        self::assertSame(['rejected', MediaRejectReason::WrongSize->value, null, null, null, null], $this->row(self::FIRST_MEDIA));
        self::assertFalse($this->storage()->fileExists($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame([], $this->bucketKeys());
        self::assertSame(0, $this->encoder()->encodeCount);
    }

    #[Test]
    public function aPictureOf50000By50000PixelsIsRejectedFromItsHeaderAndNeverEncoded(): void
    {
        $this->uploadAndQueue(self::FIRST_MEDIA, ImageFixtures::pngClaiming(50_000, 50_000));

        $this->relayAndHandle();

        self::assertSame(['rejected', MediaRejectReason::TooManyPixels->value, null, null, null, null], $this->row(self::FIRST_MEDIA));
        self::assertSame(0, $this->encoder()->encodeCount);
        self::assertSame([], $this->bucketKeys());
    }

    #[Test]
    public function aSecondUploadOfTheSamePictureReusesTheVariantsOfTheFirstAndStoresNoNewObject(): void
    {
        $original = ImageFixtures::png(300, 200);
        $this->uploadAndQueue(self::FIRST_MEDIA, $original);
        $this->relayAndHandle();
        $bucketAfterFirst = $this->bucketKeys();
        self::assertEquals([new MediaReady(self::ACME_FOODS, self::FIRST_MEDIA, new \DateTimeImmutable(self::NOW))], $this->mediaEventsInTheOutbox());
        $this->uploadAndQueue(self::SECOND_MEDIA, $original);

        // The relay moves the first MediaReady out of the outbox too, so what is left is what the second run wrote.
        $this->relayAndHandle();

        self::assertSame($this->row(self::FIRST_MEDIA), $this->row(self::SECOND_MEDIA));
        self::assertSame($bucketAfterFirst, $this->bucketKeys());
        self::assertSame(1, $this->encoder()->encodeCount);
        self::assertFalse($this->storage()->fileExists($this->uploadKey(self::SECOND_MEDIA)));
        self::assertEquals([new MediaReady(self::ACME_FOODS, self::SECOND_MEDIA, new \DateTimeImmutable(self::NOW))], $this->mediaEventsInTheOutbox());
    }

    #[Test]
    public function aRepeatedMessageIsHandledOnceAndLeavesTheRowTheBucketAndTheOutboxAsTheFirstRunLeftThem(): void
    {
        $this->uploadAndQueue(self::FIRST_MEDIA, ImageFixtures::png(64, 64));
        $this->relayAndHandle();
        $rowAfterFirst = $this->row(self::FIRST_MEDIA);
        $bucketAfterFirst = $this->bucketKeys();
        $handled = $this->asyncMedia()->getSent();
        self::assertCount(1, $handled);
        $this->storage()->write($this->uploadKey(self::FIRST_MEDIA), 'a file that must stay');

        $this->asyncMedia()->send($handled[0]);
        self::runTheWorkerUntilIdle('async_media');

        self::assertSame($rowAfterFirst, $this->row(self::FIRST_MEDIA));
        self::assertSame('a file that must stay', $this->storage()->read($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame(array_merge($bucketAfterFirst, [$this->uploadKey(self::FIRST_MEDIA)]), $this->bucketKeys(includeUploads: true));
        self::assertCount(1, $this->mediaEventsInTheOutbox());
        self::assertSame(1, $this->encoder()->encodeCount);
        self::assertSame(1, $this->ledgerRows(), 'The ledger skipped the redelivered message.');
    }

    #[Test]
    public function aNewMessageForAnObjectThatIsReadyAlreadyChangesNothing(): void
    {
        $this->uploadAndQueue(self::FIRST_MEDIA, ImageFixtures::png(64, 64));
        $this->relayAndHandle();
        self::assertCount(1, $this->mediaEventsInTheOutbox());
        $rowAfterFirst = $this->row(self::FIRST_MEDIA);
        $this->storage()->write($this->uploadKey(self::FIRST_MEDIA), 'a file that must stay');
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->queue()->queue(new ProcessUpload(self::ACME_FOODS, self::FIRST_MEDIA)));

        // The relay moves the first MediaReady out of the outbox too, so what is left is what the second run wrote.
        $this->relayAndHandle();

        self::assertSame($rowAfterFirst, $this->row(self::FIRST_MEDIA));
        self::assertSame('a file that must stay', $this->storage()->read($this->uploadKey(self::FIRST_MEDIA)));
        self::assertSame([], $this->mediaEventsInTheOutbox());
        self::assertSame(2, $this->ledgerRows(), 'The second message had its own id, so only the status guard stopped it.');
    }

    private function relayAndHandle(): void
    {
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_media');
    }

    private function uploadAndQueue(string $mediaId, string $bytes, string $mime = 'image/png', ?int $declaredBytes = null): void
    {
        $this->storage()->write($this->uploadKey($mediaId), $bytes);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($mediaId, $mime, $bytes, $declaredBytes): void {
            $this->mediaObjects()->add(MediaObject::upload(new MediaId($mediaId), new OrganizationId(self::ACME_FOODS), $this->uploadKey($mediaId), $mime, $declaredBytes ?? \strlen($bytes), new UserId(self::MIA), new \DateTimeImmutable('2026-03-02 09:58:00 UTC')));
            $this->queue()->queue(new ProcessUpload(self::ACME_FOODS, $mediaId));
        });
    }

    /**
     * The row as status, reject reason, hash, width, height and variants (sorted by name).
     *
     * @return list<mixed>
     */
    private function row(string $mediaId): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($mediaId): array {
            $row = $this->connection()->fetchAssociative('SELECT status, reject_reason, sha256, width, height, variants::text AS variants FROM med_object WHERE id = ?', [$mediaId]);
            self::assertIsArray($row);
            $variants = $row['variants'];
            if (\is_string($variants)) {
                $variants = json_decode($variants, true, 512, \JSON_THROW_ON_ERROR);
                self::assertIsArray($variants);
                ksort($variants);
            }

            return [$row['status'], $row['reject_reason'], $row['sha256'], $row['width'], $row['height'], $variants];
        });
    }

    /**
     * @return list<MediaEvent> the media events of the outbox, oldest first
     */
    private function mediaEventsInTheOutbox(): array
    {
        $serializer = new PhpSerializer();
        $events = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' ORDER BY id") as $body) {
            self::assertIsString($body);
            $message = $serializer->decode(['body' => $body])->getMessage();
            if ($message instanceof MediaEvent) {
                $events[] = $message;
            }
        }

        return $events;
    }

    private function ledgerRows(): int
    {
        $count = $this->migrator()->fetchOne('SELECT count(*) FROM processed_message WHERE handler = ?', [CleanUpload::HANDLER_NAME]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<string> the keys under the organization's media/ prefix, and under uploads/ too when asked, sorted
     */
    private function bucketKeys(bool $includeUploads = false): array
    {
        $prefixes = $includeUploads ? ['media/'.self::ACME_FOODS, 'uploads/'.self::ACME_FOODS] : ['media/'.self::ACME_FOODS];
        $keys = [];
        foreach ($prefixes as $prefix) {
            foreach ($this->storage()->listContents($prefix, true) as $item) {
                if ($item->isFile()) {
                    $keys[] = $item->path();
                }
            }
        }
        sort($keys);

        return $keys;
    }

    private function cleanUp(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS);
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [CleanUpload::HANDLER_NAME]);
        $this->storage()->deleteDirectory('media/'.self::ACME_FOODS);
        $this->storage()->deleteDirectory('uploads/'.self::ACME_FOODS);
    }

    private function uploadKey(string $mediaId): string
    {
        return 'uploads/'.self::ACME_FOODS.'/'.$mediaId;
    }

    private function variantKey(string $mediaId, string $variant): string
    {
        return 'media/'.self::ACME_FOODS.'/2026/03/'.$mediaId.'/'.$variant.'.webp';
    }

    private function encoder(): FakeImageEncoder
    {
        $encoder = self::getContainer()->get(FakeImageEncoder::class);
        self::assertInstanceOf(FakeImageEncoder::class, $encoder);

        return $encoder;
    }

    private function asyncMedia(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_media');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get('test.media_object_storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    private function queue(): MessengerUploadProcessingQueue
    {
        return new MessengerUploadProcessingQueue(self::getContainer()->get('command.bus'), $this->connection());
    }

    private function mediaObjects(): DoctrineMediaObjects
    {
        return new DoctrineMediaObjects($this->entityManager());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function mediaRows(): MediaRows
    {
        return new MediaRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
