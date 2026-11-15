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

    private function addMedia(string $organizationId, string $mediaId, string $status): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $mediaId, $status): void {
            $mediaObject = MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), 'uploads/'.$organizationId.'/'.$mediaId, 'image/png', 2048, new UserId(self::MIA), new \DateTimeImmutable('2026-04-07 09:00:00 UTC'));
            if ('ready' === $status) {
                $mediaObject->markReady(hash('sha256', $mediaId), 1280, 800, ['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp']);
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
