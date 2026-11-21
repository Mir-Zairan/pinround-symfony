<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Api\MediaImage;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\Media\Application\SignedVariantUrls;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectReason;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Media\Persistence\MediaRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SignedUrls as the container wires it, on the real med_object table as pinround_app under row-level security. Acme
 * Foods has three ready pictures, a pending and a rejected one; another organization has a ready one. Signed at
 * 2026-04-07 09:30 UTC.
 */
final class SignedUrlsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5201';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f5202';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f5203';
    private const array ACME_READY = [
        '0197a3c0-0000-7000-8000-0000000f5211',
        '0197a3c0-0000-7000-8000-0000000f5212',
        '0197a3c0-0000-7000-8000-0000000f5213',
    ];
    private const string ACME_PENDING = '0197a3c0-0000-7000-8000-0000000f5214';
    private const string ACME_REJECTED = '0197a3c0-0000-7000-8000-0000000f5215';
    private const string OTHER_READY = '0197a3c0-0000-7000-8000-0000000f5216';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-0000000f5219';
    private const string SIGNED_AT = '2026-04-07 09:30:00 UTC';

    protected function setUp(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        foreach (self::ACME_READY as $mediaId) {
            $this->addMedia(self::ACME_FOODS, $mediaId, 'ready');
        }
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, 'pending');
        $this->addMedia(self::ACME_FOODS, self::ACME_REJECTED, 'rejected');
        $this->addMedia(self::OTHER_ORGANIZATION, self::OTHER_READY, 'ready');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function theApiIsTheSignedVariantUrls(): void
    {
        self::assertInstanceOf(SignedVariantUrls::class, self::getContainer()->get('test.media_signed_urls'));
    }

    #[Test]
    public function aWholePageOfPicturesIsReadInOneQuery(): void
    {
        $askedIds = [...self::ACME_READY, self::ACME_PENDING, self::ACME_REJECTED, self::OTHER_READY, self::UNKNOWN];
        $debugData = $this->debugData();

        [$images, $queryCount] = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($askedIds, $debugData): array {
            $debugData->reset();
            $images = $this->forVariants($askedIds);

            return [$images, self::queryCountOf($debugData)];
        });

        self::assertSame([...self::ACME_READY, self::ACME_PENDING, self::ACME_REJECTED], array_keys($images));
        self::assertSame(
            [MediaImageStatus::Ready, MediaImageStatus::Ready, MediaImageStatus::Ready, MediaImageStatus::Pending, MediaImageStatus::Rejected],
            array_values(array_map(static fn (MediaImage $image): MediaImageStatus => $image->status, $images)),
        );
        self::assertSame('/pinround/media/'.self::ACME_READY[1].'/view.webp', parse_url((string) $images[self::ACME_READY[1]]->url, \PHP_URL_PATH));
        self::assertSame([1280, 800], [$images[self::ACME_READY[1]]->width, $images[self::ACME_READY[1]]->height]);
        self::assertSame(MediaRejectReason::Unreadable->value, $images[self::ACME_REJECTED]->refusal);
        self::assertSame(1, $queryCount);
    }

    /**
     * The thumb is the view scaled down to ImageEncoder::THUMB_MAX_WIDTH, never up: a wide shot, a phone shot whose
     * height rounds to the nearest pixel, a view narrower than the thumb's width, and a picture stored with no thumb.
     */
    #[Test]
    public function eachThumbIsSizedFromItsViewInTheSameOneQuery(): void
    {
        $wide = '0197a3c0-0000-7000-8000-0000000f5221';
        $phone = '0197a3c0-0000-7000-8000-0000000f5222';
        $narrow = '0197a3c0-0000-7000-8000-0000000f5223';
        $withoutThumb = '0197a3c0-0000-7000-8000-0000000f5224';
        $this->addMedia(self::ACME_FOODS, $wide, 'ready', 1920, 960);
        $this->addMedia(self::ACME_FOODS, $phone, 'ready', 1170, 2532);
        $this->addMedia(self::ACME_FOODS, $narrow, 'ready', 320, 200);
        $this->addMedia(self::ACME_FOODS, $withoutThumb, 'ready', 640, 400, hasThumb: false);
        $debugData = $this->debugData();

        [$images, $queryCount] = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($wide, $phone, $narrow, $withoutThumb, $debugData): array {
            $debugData->reset();
            $images = $this->signedUrls()->forVariants(new OrganizationId(self::ACME_FOODS), [$wide, $phone, $narrow, $withoutThumb, self::ACME_PENDING], 'thumb', new \DateTimeImmutable(self::SIGNED_AT));

            return [$images, self::queryCountOf($debugData)];
        });

        self::assertSame(
            [$wide => [480, 240], $phone => [480, 1039], $narrow => [320, 200], $withoutThumb => [640, 400], self::ACME_PENDING => [null, null]],
            array_map(static fn (MediaImage $image): array => [$image->width, $image->height], $images),
        );
        self::assertSame(
            [$wide => 'thumb.webp', $phone => 'thumb.webp', $narrow => 'thumb.webp', $withoutThumb => 'view.webp'],
            array_map(static fn (MediaImage $image): string => basename((string) parse_url((string) $image->url, \PHP_URL_PATH)), array_filter($images, static fn (MediaImage $image): bool => null !== $image->url)),
        );
        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function rowLevelSecurityAloneLeavesOutAnotherOrganizationsReadyPictureWithTheTenantFilterOff(): void
    {
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $images = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->forVariants([self::OTHER_READY, self::ACME_READY[0]]));
        $asTheOther = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->signedUrls()->forVariants(new OrganizationId(self::OTHER_ORGANIZATION), [self::OTHER_READY, self::ACME_READY[0]], 'view', new \DateTimeImmutable(self::SIGNED_AT)));

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame([self::ACME_READY[0]], array_keys($images));
        self::assertSame([self::OTHER_READY], array_keys($asTheOther), 'The other organization does not see its own picture either, so the check above proves nothing.');
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, MediaImage>
     */
    private function forVariants(array $mediaIds): array
    {
        return $this->signedUrls()->forVariants(new OrganizationId(self::ACME_FOODS), $mediaIds, 'view', new \DateTimeImmutable(self::SIGNED_AT));
    }

    /**
     * @param int $viewWidth  of a ready picture's view, in pixels
     * @param int $viewHeight of a ready picture's view, in pixels
     */
    private function addMedia(string $organizationId, string $mediaId, string $status, int $viewWidth = 1280, int $viewHeight = 800, bool $hasThumb = true): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $mediaId, $status, $viewWidth, $viewHeight, $hasThumb): void {
            $mediaObject = MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), 'uploads/'.$organizationId.'/'.$mediaId, 'image/png', 2048, new UserId(self::MIA), new \DateTimeImmutable('2026-04-07 09:00:00 UTC'));
            if ('ready' === $status) {
                $variants = ['view' => 'media/'.$mediaId.'/view.webp'];
                if ($hasThumb) {
                    $variants['thumb'] = 'media/'.$mediaId.'/thumb.webp';
                }
                $mediaObject->markReady(hash('sha256', $mediaId), $viewWidth, $viewHeight, $variants);
            }
            if ('rejected' === $status) {
                $mediaObject->reject(MediaRejectReason::Unreadable->value);
            }
            new DoctrineMediaObjects($this->entityManager())->add($mediaObject);
        });
    }

    private function signedUrls(): SignedUrls
    {
        $signedUrls = self::getContainer()->get('test.media_signed_urls');
        \assert($signedUrls instanceof SignedUrls);

        return $signedUrls;
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
        return new MediaRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
