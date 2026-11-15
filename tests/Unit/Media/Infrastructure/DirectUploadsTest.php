<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadSlot;
use App\Media\Application\ProcessUpload;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadPurpose;
use App\Media\Infrastructure\DirectUploads;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedUploads;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * DirectUploads over in-memory ports that behave like the Doctrine ones. The clock stands at 2026-03-02 10:00 UTC, so
 * an intent begun then expires at 2026-03-03 10:00 UTC.
 */
final class DirectUploadsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1501';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000e1502';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1503';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e1504';
    private const string NOW = '2026-03-02 10:00:00 UTC';

    private MockClock $clock;
    private CountingClock $signingClock;
    private TenantContext $tenantContext;
    private InMemoryTransactions $transactions;
    private InMemoryUploadIntents $uploadIntents;
    private InMemoryMediaObjects $mediaObjects;
    private CommitAwareMessageBus $commandBus;
    private DirectUploads $uploads;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->signingClock = new CountingClock($this->clock);
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->transactions = new InMemoryTransactions($this->tenantContext);
        $this->uploadIntents = new InMemoryUploadIntents($this->transactions, $this->tenantContext, $this->clock);
        $this->mediaObjects = new InMemoryMediaObjects($this->transactions, $this->tenantContext);
        $this->commandBus = new CommitAwareMessageBus($this->transactions);
        $this->uploads = new DirectUploads(
            $this->uploadIntents,
            $this->mediaObjects,
            new PresignedUploads(new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'eu-west-1', 'acme-key', 'acme-secret'), $this->signingClock),
            $this->clock,
            $this->transactions,
            $this->tenantContext,
            $this->commandBus,
        );
    }

    #[Test]
    public function aGoodBeginStoresAnIntentForTheDeclaredFileAndSignsAUrlThatPinsItsTypeAndLength(): void
    {
        $slot = $this->begin('image/png', 2048);

        $intents = $this->uploadIntents->storedRows();
        self::assertCount(1, $intents);
        $intent = $intents[0];
        self::assertSame($slot->intentId, $intent->id()->toString());
        self::assertSame(self::ACME_FOODS, $intent->organizationId()->toString());
        self::assertSame(self::MIA, $intent->userId()->toString());
        self::assertSame(2048, $intent->maxBytes());
        self::assertSame(['image/png'], $intent->allowedMimeTypes());
        self::assertSame('uploads/'.self::ACME_FOODS.'/'.$slot->intentId, $intent->storageKey());
        self::assertEquals(new \DateTimeImmutable('2026-03-03 10:00:00 UTC'), $intent->expiresAt());
        self::assertNull($intent->usedAt());

        self::assertSame('/pinround/uploads/'.self::ACME_FOODS.'/'.$slot->intentId, parse_url($slot->url, \PHP_URL_PATH));
        parse_str((string) parse_url($slot->url, \PHP_URL_QUERY), $query);
        self::assertIsString($query['X-Amz-SignedHeaders'] ?? null);
        self::assertEqualsCanonicalizing(['content-length', 'content-type', 'host'], explode(';', $query['X-Amz-SignedHeaders']));
        self::assertSame(['Content-Type' => 'image/png', 'Content-Length' => '2048'], $slot->headers);
        self::assertEquals(new \DateTimeImmutable('2026-03-02 10:05:00 UTC'), $slot->expiresAt);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sizesAtTheBounds(): iterable
    {
        yield 'one byte' => ['image/jpeg', 1];
        yield 'exactly 15 MB' => ['image/webp', 15 * 1024 * 1024];
    }

    #[Test]
    #[DataProvider('sizesAtTheBounds')]
    public function aFileAtASizeBoundIsTaken(string $mime, int $bytes): void
    {
        $slot = $this->begin($mime, $bytes);

        self::assertSame(['Content-Type' => $mime, 'Content-Length' => (string) $bytes], $slot->headers);
        self::assertSame([$bytes], array_map(static fn (UploadIntent $intent): int => $intent->maxBytes(), $this->uploadIntents->storedRows()));
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function refusedUploads(): iterable
    {
        $notAScreenshot = 'This file is not a PNG, JPEG or WebP screenshot.';
        $outOfSize = 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.';
        $unknownPurpose = 'Only screenshots can be uploaded here.';

        yield 'a GIF' => ['screenshot', 'image/gif', 2048, $notAScreenshot];
        yield 'a type with parameters' => ['screenshot', 'image/png; charset=binary', 2048, $notAScreenshot];
        yield 'a type in capitals' => ['screenshot', 'IMAGE/PNG', 2048, $notAScreenshot];
        yield 'no type' => ['screenshot', '', 2048, $notAScreenshot];
        yield '16 MB' => ['screenshot', 'image/png', 16 * 1024 * 1024, $outOfSize];
        yield 'a byte over 15 MB' => ['screenshot', 'image/png', 15 * 1024 * 1024 + 1, $outOfSize];
        yield '0 bytes' => ['screenshot', 'image/png', 0, $outOfSize];
        yield 'a negative size' => ['screenshot', 'image/png', -1, $outOfSize];
        yield 'an unknown purpose' => ['avatar', 'image/png', 2048, $unknownPurpose];
        yield 'a purpose in capitals' => ['Screenshot', 'image/png', 2048, $unknownPurpose];
        yield 'the purpose before the type' => ['avatar', 'image/gif', 0, $unknownPurpose];
        yield 'the type before the size' => ['screenshot', 'image/gif', 0, $notAScreenshot];
    }

    #[Test]
    #[DataProvider('refusedUploads')]
    public function aRefusedBeginStoresNoIntentAndSignsNoUrl(string $purpose, string $mime, int $bytes, string $refusal): void
    {
        try {
            $this->transactions->run(fn (): UploadSlot => $this->uploads->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $purpose, $mime, $bytes));
            self::fail('A refused upload was begun.');
        } catch (UploadRefused $refused) {
            self::assertSame($refusal, $refused->getMessage());
        }

        self::assertSame([], $this->uploadIntents->storedRows());
        self::assertSame(0, $this->signingClock->readCount);
    }

    #[Test]
    public function aBeginOutsideATransactionStoresNothingAndSignsNoUrl(): void
    {
        try {
            $this->uploads->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'screenshot', 'image/png', 2048);
            self::fail('An upload was begun outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Adding an upload intent needs an open transaction.', $refusal->getMessage());
        }

        self::assertSame([], $this->uploadIntents->storedRows());
        self::assertSame(0, $this->signingClock->readCount);
    }

    #[Test]
    public function aBeginForAnotherOrganizationThanTheTenantStoresNothingAndSignsNoUrl(): void
    {
        try {
            $this->transactions->run(fn (): UploadSlot => $this->uploads->begin(new OrganizationId(self::OTHER_ORGANIZATION), new UserId(self::MIA), 'screenshot', 'image/png', 2048));
            self::fail('An upload was begun for another organization than the tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('An upload runs under the tenant of its own organization.', $refusal->getMessage());
        }

        self::assertSame([], $this->uploadIntents->storedRows());
        self::assertSame(0, $this->signingClock->readCount);
    }

    #[Test]
    public function completingByTheCreatorAddsOnePendingMediaObjectAndQueuesItsProcessingAfterTheCommit(): void
    {
        $slot = $this->begin('image/png', 2048);
        $this->clock->modify('+2 minutes');

        $mediaId = $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);

        $mediaObjects = $this->mediaObjects->storedRows();
        self::assertCount(1, $mediaObjects);
        $mediaObject = $mediaObjects[0];
        self::assertSame($mediaId, $mediaObject->id()->toString());
        self::assertSame(self::ACME_FOODS, $mediaObject->organizationId()->toString());
        self::assertSame(MediaStatus::Pending, $mediaObject->status());
        self::assertSame('uploads/'.self::ACME_FOODS.'/'.$slot->intentId, $mediaObject->storageKey());
        self::assertSame('image/png', $mediaObject->mime());
        self::assertSame(2048, $mediaObject->bytes());
        self::assertSame(self::MIA, $mediaObject->uploadedBy()->toString());
        self::assertEquals(new \DateTimeImmutable('2026-03-02 10:02:00 UTC'), $mediaObject->createdAt());
        self::assertEquals([new \DateTimeImmutable('2026-03-02 10:02:00 UTC')], $this->storedUsedTimes());
        self::assertEquals(
            [['message' => new ProcessUpload(self::ACME_FOODS, $mediaId), 'wasTransactionOpen' => false]],
            $this->commandBus->dispatched(),
        );
    }

    #[Test]
    public function aSecondCompleteIsRefusedAndAddsNoSecondMediaObjectNorMessage(): void
    {
        $slot = $this->begin('image/png', 2048);
        $firstMediaId = $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);
        $this->clock->modify('+1 minute');

        try {
            $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);
            self::fail('An upload was completed twice.');
        } catch (UploadRefused $refused) {
            self::assertSame('This upload has ended. Upload the file again.', $refused->getMessage());
        }

        self::assertSame([$firstMediaId], $this->storedMediaIds());
        self::assertCount(1, $this->commandBus->dispatched());
        self::assertEquals([new \DateTimeImmutable(self::NOW)], $this->storedUsedTimes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timesAtOrAfterTheExpiry(): iterable
    {
        yield 'at the expiry' => ['+1 day'];
        yield 'a minute after it' => ['+1 day +1 minute'];
    }

    #[Test]
    #[DataProvider('timesAtOrAfterTheExpiry')]
    public function completingAnExpiredIntentIsRefusedAndChangesNothing(string $timeLater): void
    {
        $slot = $this->begin('image/png', 2048);
        $this->clock->modify($timeLater);

        try {
            $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);
            self::fail('An expired upload was completed.');
        } catch (UploadRefused $refused) {
            self::assertSame('This upload has ended. Upload the file again.', $refused->getMessage());
        }

        $this->assertNothingChangedSinceTheBegin();
    }

    #[Test]
    public function anIntentExpiringASecondFromNowCanStillBeCompleted(): void
    {
        $slot = $this->begin('image/png', 2048);
        $this->clock->modify('+1 day -1 second');

        $mediaId = $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);

        self::assertSame([$mediaId], $this->storedMediaIds());
    }

    #[Test]
    public function completingAnotherPersonsUploadIsNotFoundAndChangesNothing(): void
    {
        $slot = $this->begin('image/png', 2048);

        $this->expectNotFound(fn (): string => $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $slot->intentId));

        $this->assertNothingChangedSinceTheBegin();
    }

    #[Test]
    public function completingAnotherOrganizationsUploadIsNotFoundAndChangesNothing(): void
    {
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));
        $slot = $this->transactions->run(fn (): UploadSlot => $this->uploads->begin(new OrganizationId(self::OTHER_ORGANIZATION), new UserId(self::MIA), 'screenshot', 'image/png', 2048));
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $this->expectNotFound(fn (): string => $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId));

        $this->assertNothingChangedSinceTheBegin();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function intentIdsOfNoIntent(): iterable
    {
        yield 'not an id' => ['not-an-id'];
        yield 'empty' => [''];
        yield 'an id with a trailing newline' => ["0197a3c0-0000-7000-8000-0000000e1519\n"];
        yield 'a UUIDv4' => ['0197a3c0-0000-4000-8000-0000000e1519'];
        yield 'a well-formed id nobody began' => ['0197a3c0-0000-7000-8000-0000000e1519'];
    }

    #[Test]
    #[DataProvider('intentIdsOfNoIntent')]
    public function completingAnIdOfNoIntentIsNotFoundAndChangesNothing(string $intentId): void
    {
        $this->begin('image/png', 2048);

        $this->expectNotFound(fn (): string => $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $intentId));

        $this->assertNothingChangedSinceTheBegin();
    }

    #[Test]
    public function aFailureInsideTheTransactionUndoesTheUseAndQueuesNothing(): void
    {
        $slot = $this->begin('image/png', 2048);
        $this->mediaObjects->nextAddFailure = new \RuntimeException('The database went away.');

        try {
            $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);
            self::fail('A complete whose transaction failed went through.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The database went away.', $failure->getMessage());
        }

        $this->assertNothingChangedSinceTheBegin();
    }

    #[Test]
    public function anIntentAllowingMoreThanOneTypeIsNotCompletedAsTheTypeWouldBeAGuess(): void
    {
        $intentId = $this->uploadIntents->nextIdentity();
        $this->transactions->run(fn () => $this->uploadIntents->add(UploadIntent::open($intentId, new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), UploadPurpose::Screenshot, 2048, ['image/png', 'image/webp'], 'uploads/'.self::ACME_FOODS.'/'.$intentId->toString(), new \DateTimeImmutable('2026-03-03 10:00:00 UTC'))));

        try {
            $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $intentId->toString());
            self::fail('An intent of two types was completed.');
        } catch (\LogicException $refusal) {
            self::assertSame('A direct upload intent allows exactly one media type.', $refusal->getMessage());
        }

        $this->assertNothingChangedSinceTheBegin();
    }

    #[Test]
    public function completingForAnotherOrganizationThanTheTenantIsRefusedAndChangesNothing(): void
    {
        $slot = $this->begin('image/png', 2048);

        try {
            $this->uploads->complete(new OrganizationId(self::OTHER_ORGANIZATION), new UserId(self::MIA), $slot->intentId);
            self::fail('An upload was completed for another organization than the tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('An upload runs under the tenant of its own organization.', $refusal->getMessage());
        }

        $this->assertNothingChangedSinceTheBegin();
    }

    #[Test]
    public function completingWithNoTenantIsRefusedAndChangesNothing(): void
    {
        $slot = $this->begin('image/png', 2048);
        $this->tenantContext->clear();

        try {
            $this->uploads->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId);
            self::fail('An upload was completed with no tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('An upload runs under the tenant of its own organization.', $refusal->getMessage());
        }

        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->assertNothingChangedSinceTheBegin();
    }

    private function begin(string $mime, int $bytes): UploadSlot
    {
        return $this->transactions->run(fn (): UploadSlot => $this->uploads->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'screenshot', $mime, $bytes));
    }

    /**
     * @param \Closure(): string $complete
     */
    private function expectNotFound(\Closure $complete): void
    {
        try {
            $complete();
            self::fail('An upload was completed that the person did not begin.');
        } catch (UploadIntentNotFound) {
        }
    }

    private function assertNothingChangedSinceTheBegin(): void
    {
        self::assertNotSame([], $this->uploadIntents->storedRows(), 'No intent was begun, so its staying unused proves nothing.');
        self::assertSame([null], array_values(array_unique(array_map(static fn (UploadIntent $intent): ?string => $intent->usedAt()?->format(\DATE_ATOM), $this->uploadIntents->storedRows()))));
        self::assertSame([], $this->mediaObjects->storedRows());
        self::assertSame([], $this->commandBus->dispatched());
    }

    /**
     * @return list<\DateTimeImmutable|null>
     */
    private function storedUsedTimes(): array
    {
        return array_map(static fn (UploadIntent $intent): ?\DateTimeImmutable => $intent->usedAt(), $this->uploadIntents->storedRows());
    }

    /**
     * @return list<string>
     */
    private function storedMediaIds(): array
    {
        return array_map(static fn (MediaObject $mediaObject): string => $mediaObject->id()->toString(), $this->mediaObjects->storedRows());
    }
}
