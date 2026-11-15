<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Api\MediaImage;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\Media\Application\SignedVariantUrls;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectReason;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedDownloads;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SignedVariantUrls over the in-memory media objects and the real signer, which signs locally. URLs are signed at
 * 2026-04-07 09:30 UTC; no clock is read. Acme Foods has a ready, a deduplicated ready, a pending and a rejected
 * picture; another organization has a ready one.
 */
final class SignedVariantUrlsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5101';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f5102';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f5103';
    private const string READY = '0197a3c0-0000-7000-8000-0000000f5111';
    private const string READY_DUPLICATE = '0197a3c0-0000-7000-8000-0000000f5112';
    private const string PENDING = '0197a3c0-0000-7000-8000-0000000f5113';
    private const string REJECTED = '0197a3c0-0000-7000-8000-0000000f5114';
    private const string OTHER_READY = '0197a3c0-0000-7000-8000-0000000f5115';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-0000000f5119';
    private const string SIGNED_AT = '2026-04-07 09:30:00 UTC';
    private const string PICTURE_HASH = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    private TenantContext $tenantContext;
    private InMemoryTransactions $transactions;
    private InMemoryMediaObjects $mediaObjects;
    private SignedVariantUrls $signedUrls;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->transactions = new InMemoryTransactions($this->tenantContext);
        $this->mediaObjects = new InMemoryMediaObjects($this->transactions, $this->tenantContext);
        $this->signedUrls = new SignedVariantUrls(
            $this->mediaObjects,
            new PresignedDownloads(new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'eu-west-1', 'acme-key', 'acme-secret')),
            $this->tenantContext,
        );
        $this->seed();
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
    }

    #[Test]
    public function aReadyViewGetsAUrlSignedForFiveMinutesFromTheTimeGivenThatServesAnInlineWebp(): void
    {
        $image = $this->forVariants([self::READY], 'view')[self::READY] ?? null;

        self::assertNotNull($image);
        self::assertSame([self::READY, MediaImageStatus::Ready, 1280, 800, null], [$image->mediaId, $image->status, $image->width, $image->height, $image->refusal]);
        self::assertIsString($image->url);
        self::assertSame('files.example.test', parse_url($image->url, \PHP_URL_HOST));
        self::assertSame('/pinround/'.self::viewKeyOf(self::READY), parse_url($image->url, \PHP_URL_PATH));
        $query = self::query($image->url);
        self::assertSame('300', $query['X-Amz-Expires'] ?? null);
        self::assertSame('20260407T093000Z', $query['X-Amz-Date'] ?? null);
        self::assertSame('image/webp', $query['response-content-type'] ?? null);
        self::assertSame('inline', $query['response-content-disposition'] ?? null);
        self::assertSame('host', $query['X-Amz-SignedHeaders'] ?? null);
        self::assertStringNotContainsString('acme-secret', $image->url);
    }

    #[Test]
    public function theTimeGivenAndNotAnyClockDecidesWhenTheUrlStarts(): void
    {
        $images = $this->transactions->run(fn (): array => $this->signedUrls->forVariants(new OrganizationId(self::ACME_FOODS), [self::READY], 'view', new \DateTimeImmutable('2026-04-07 11:45:10 UTC')));

        self::assertSame('20260407T114510Z', self::query((string) $images[self::READY]->url)['X-Amz-Date'] ?? null);
    }

    #[Test]
    public function aReadyThumbGetsAUrlOfItsThumbAndNoSizeAsOnlyTheViewsIsKept(): void
    {
        $image = $this->forVariants([self::READY], 'thumb')[self::READY] ?? null;

        self::assertNotNull($image);
        self::assertSame('/pinround/media/'.self::READY.'/thumb.webp', parse_url((string) $image->url, \PHP_URL_PATH));
        self::assertSame([MediaImageStatus::Ready, null, null], [$image->status, $image->width, $image->height]);
    }

    #[Test]
    public function aDeduplicatedPictureIsSignedForTheStoredKeyItPointsAtNotOneBuiltFromItsOwnId(): void
    {
        $image = $this->forVariants([self::READY_DUPLICATE], 'view')[self::READY_DUPLICATE] ?? null;

        self::assertNotNull($image);
        self::assertSame('/pinround/'.self::viewKeyOf(self::READY), parse_url((string) $image->url, \PHP_URL_PATH));
    }

    #[Test]
    public function aPendingPictureHasNoUrlAndARejectedOneHasNoUrlButItsReason(): void
    {
        $images = $this->forVariants([self::PENDING, self::REJECTED], 'view');

        self::assertEquals(
            [self::PENDING => MediaImage::pending(self::PENDING), self::REJECTED => MediaImage::rejected(self::REJECTED, MediaRejectReason::NotAnImage->value)],
            $images,
        );
        self::assertNull($images[self::PENDING]->url);
        self::assertNull($images[self::REJECTED]->url);
        self::assertSame('This file is not a PNG, JPEG or WebP screenshot.', $images[self::REJECTED]->refusal);
    }

    #[Test]
    public function unknownMalformedAndAnotherOrganizationsIdsAreLeftOutAndTheRestKeepTheOrderAskedFor(): void
    {
        $images = $this->forVariants([self::REJECTED, self::UNKNOWN, 'not-a-media-id', self::OTHER_READY, "\u{FEFF}".self::PENDING, self::PENDING."\n", self::READY], 'view');

        self::assertSame([self::REJECTED, self::READY], array_keys($images));
        self::assertSame([self::REJECTED, self::READY], array_map(static fn (MediaImage $image): string => $image->mediaId, array_values($images)));
    }

    #[Test]
    public function anIdGivenTwiceInAnyCaseIsAnsweredOnceUnderItsLowercaseForm(): void
    {
        $images = $this->forVariants([strtoupper(self::READY), self::READY], 'view');

        self::assertSame([self::READY], array_keys($images));
        self::assertSame([[self::READY]], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function allIdsAreReadInOneRead(): void
    {
        $this->forVariants([self::READY, self::PENDING, self::REJECTED, self::READY_DUPLICATE], 'view');

        self::assertSame([[self::READY, self::PENDING, self::REJECTED, self::READY_DUPLICATE]], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function anEmptyListOrOneOfOnlyMalformedIdsIsAnsweredEmptyWithoutAnyRead(): void
    {
        self::assertSame([], $this->forVariants([], 'view'));
        self::assertSame([], $this->forVariants(['', 'SHOP-12'], 'view'));
        self::assertSame([], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function exactlyTheMostIdsAreTaken(): void
    {
        $ids = array_merge([self::READY], array_fill(0, SignedUrls::MAX_MEDIA_IDS - 1, self::UNKNOWN));

        self::assertSame([self::READY], array_keys($this->forVariants($ids, 'view')));
    }

    #[Test]
    public function oneIdOverTheMostIsRefusedBeforeAnyRead(): void
    {
        $ids = array_merge([self::READY], array_fill(0, SignedUrls::MAX_MEDIA_IDS, self::UNKNOWN));

        try {
            $this->forVariants($ids, 'view');
            self::fail('A list over the most ids was taken.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('Signed URLs are made for at most 200 pictures at once.', $refusal->getMessage());
        }
        self::assertSame([], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function anUnknownVariantIsRefusedBeforeAnyRead(): void
    {
        foreach (['original', 'VIEW', 'view.webp', ''] as $variant) {
            try {
                $this->forVariants([self::READY], $variant);
                self::fail(\sprintf('The variant "%s" was taken.', $variant));
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame('A picture variant is "view" or "thumb".', $refusal->getMessage());
            }
        }
        self::assertSame([], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function anotherOrganizationsTenantIsRefusedBeforeAnyRead(): void
    {
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));

        try {
            $this->transactions->run(fn (): array => $this->signedUrls->forVariants(new OrganizationId(self::ACME_FOODS), [self::READY], 'view', new \DateTimeImmutable(self::SIGNED_AT)));
            self::fail('Signed URLs were made under another tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('Signed URLs are made under the tenant of their own organization.', $refusal->getMessage());
        }
        self::assertSame([], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function noTenantIsRefusedBeforeAnyRead(): void
    {
        $this->tenantContext->clear();

        try {
            $this->signedUrls->forVariants(new OrganizationId(self::ACME_FOODS), [self::READY], 'view', new \DateTimeImmutable(self::SIGNED_AT));
            self::fail('Signed URLs were made without a tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('Signed URLs are made under the tenant of their own organization.', $refusal->getMessage());
        }
        self::assertSame([], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function outsideATransactionNothingIsRead(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Reading media objects needs an open transaction.');

        $this->signedUrls->forVariants(new OrganizationId(self::ACME_FOODS), [self::READY], 'view', new \DateTimeImmutable(self::SIGNED_AT));
    }

    #[Test]
    public function aReadyPictureWithoutTheVariantAskedForIsAnErrorNotAnEmptyAnswer(): void
    {
        $withoutThumb = '0197a3c0-0000-7000-8000-0000000f5116';
        $this->transactions->run(function () use ($withoutThumb): void {
            $mediaObject = self::uploaded(self::ACME_FOODS, $withoutThumb);
            $mediaObject->markReady(self::PICTURE_HASH, 640, 400, ['view' => self::viewKeyOf($withoutThumb)]);
            $this->mediaObjects->add($mediaObject);
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The ready media object '.$withoutThumb.' has no thumb variant stored.');

        $this->forVariants([$withoutThumb], 'thumb');
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, MediaImage>
     */
    private function forVariants(array $mediaIds, string $variant): array
    {
        return $this->transactions->run(fn (): array => $this->signedUrls->forVariants(new OrganizationId(self::ACME_FOODS), $mediaIds, $variant, new \DateTimeImmutable(self::SIGNED_AT)));
    }

    private function seed(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->transactions->run(function (): void {
            $this->mediaObjects->add(self::ready(self::ACME_FOODS, self::READY, self::READY));
            $this->mediaObjects->add(self::uploaded(self::ACME_FOODS, self::PENDING));
            $rejected = self::uploaded(self::ACME_FOODS, self::REJECTED);
            $rejected->reject(MediaRejectReason::NotAnImage->value);
            $this->mediaObjects->add($rejected);
            $this->mediaObjects->add(self::ready(self::ACME_FOODS, self::READY_DUPLICATE, self::READY));
        });
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));
        $this->transactions->run(fn () => $this->mediaObjects->add(self::ready(self::OTHER_ORGANIZATION, self::OTHER_READY, self::OTHER_READY)));
    }

    /**
     * A ready picture whose variants are those stored for $variantsOf, itself unless it was deduplicated.
     */
    private static function ready(string $organizationId, string $mediaId, string $variantsOf): MediaObject
    {
        $mediaObject = self::uploaded($organizationId, $mediaId);
        $mediaObject->markReady(self::PICTURE_HASH, 1280, 800, ['view' => self::viewKeyOf($variantsOf), 'thumb' => 'media/'.$variantsOf.'/thumb.webp']);

        return $mediaObject;
    }

    private static function uploaded(string $organizationId, string $mediaId): MediaObject
    {
        return MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), 'uploads/'.$mediaId, 'image/png', 2048, new UserId(self::MIA), new \DateTimeImmutable('2026-04-07 09:00:00 UTC'));
    }

    private static function viewKeyOf(string $mediaId): string
    {
        return 'media/'.$mediaId.'/view.webp';
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
