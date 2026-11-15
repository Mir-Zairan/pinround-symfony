<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaStatus;
use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadPurpose;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UploadIntent and MediaObject saved through Doctrine and found again in a cleared EntityManager, in every state, so
 * every field comes back from med_upload_intent and med_object, not from the identity map. Each later state is reached
 * through the domain on a reloaded entity, so the UPDATE is mapped too.
 */
final class MediaMappingTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1101';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1102';
    private const string INTENT = '0197a3c0-0000-7000-8000-0000000e1111';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000e1121';
    // Above PostgreSQL's integer, so a 4-byte column would refuse or cut it.
    private const int LARGE_MAX_BYTES = 5_368_709_120;
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    protected function setUp(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS);
    }

    protected function tearDown(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function anOpenUploadIntentComesBackWithEveryField(): void
    {
        $this->save($this->openIntent());

        $intent = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?UploadIntent => $this->findIntent());

        self::assertNotNull($intent);
        self::assertSame(
            [self::INTENT, self::ACME_FOODS, self::MIA, UploadPurpose::Screenshot, 'ticket', 'SHOP-12', self::LARGE_MAX_BYTES, ['image/webp', 'image/png'], 'acme/uploads/'.self::INTENT, null],
            [$intent->id()->toString(), $intent->organizationId()->toString(), $intent->userId()->toString(), $intent->purpose(), $intent->targetKind(), $intent->targetId(), $intent->maxBytes(), $intent->allowedMimeTypes(), $intent->storageKey(), $intent->usedAt()],
        );
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:15:00+00:00'), $intent->expiresAt());
    }

    #[Test]
    public function aUsedUploadIntentComesBackWithWhenItWasUsed(): void
    {
        $this->save($this->openIntent());
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            ($this->findIntent() ?? throw new \LogicException('The upload intent was not saved.'))->markUsed(new \DateTimeImmutable('2027-03-15 10:07:30+00:00'));
            $this->entityManager()->flush();
        });

        $intent = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?UploadIntent => $this->findIntent());

        self::assertNotNull($intent);
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:07:30+00:00'), $intent->usedAt());
        self::assertSame([self::LARGE_MAX_BYTES, ['image/webp', 'image/png']], [$intent->maxBytes(), $intent->allowedMimeTypes()]);
    }

    #[Test]
    public function aPendingMediaObjectComesBackWithEveryField(): void
    {
        $this->save($this->pendingMedia());

        $media = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?MediaObject => $this->findMedia());

        self::assertNotNull($media);
        self::assertSame(
            [self::MEDIA, self::ACME_FOODS, 'acme/uploads/'.self::INTENT, 'image/png', self::LARGE_MAX_BYTES - 1, self::MIA, MediaStatus::Pending, null, null, null, null, null],
            [$media->id()->toString(), $media->organizationId()->toString(), $media->storageKey(), $media->mime(), $media->bytes(), $media->uploadedBy()->toString(), $media->status(), $media->rejectReason(), $media->sha256(), $media->width(), $media->height(), $media->variants()],
        );
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:08:00+00:00'), $media->createdAt());
    }

    #[Test]
    public function aReadyMediaObjectComesBackWithItsHashSizeAndVariants(): void
    {
        $this->save($this->pendingMedia());
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            ($this->findMedia() ?? throw new \LogicException('The media object was not saved.'))->markReady(self::SHA256, 1440, 900, ['thumb' => 'acme/media/thumb.webp', 'full' => 'acme/media/full.webp']);
            $this->entityManager()->flush();
        });

        $media = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?MediaObject => $this->findMedia());

        self::assertNotNull($media);
        self::assertSame([MediaStatus::Ready, null, self::SHA256, 1440, 900], [$media->status(), $media->rejectReason(), $media->sha256(), $media->width(), $media->height()]);
        $variants = $media->variants() ?? [];
        ksort($variants);
        self::assertSame(['full' => 'acme/media/full.webp', 'thumb' => 'acme/media/thumb.webp'], $variants);
        self::assertSame('acme/uploads/'.self::INTENT, $media->storageKey());
    }

    #[Test]
    public function aRejectedMediaObjectComesBackWithItsReason(): void
    {
        $this->save($this->pendingMedia());
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            ($this->findMedia() ?? throw new \LogicException('The media object was not saved.'))->reject('  Not a picture: the file is a ZIP archive ✗  ');
            $this->entityManager()->flush();
        });

        $media = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?MediaObject => $this->findMedia());

        self::assertNotNull($media);
        self::assertSame(
            [MediaStatus::Rejected, 'Not a picture: the file is a ZIP archive ✗', null, null, null, null],
            [$media->status(), $media->rejectReason(), $media->sha256(), $media->width(), $media->height(), $media->variants()],
        );
    }

    private function openIntent(): UploadIntent
    {
        return UploadIntent::open(new UploadIntentId(self::INTENT), new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), UploadPurpose::Screenshot, 'ticket', 'SHOP-12', self::LARGE_MAX_BYTES, ['image/webp', 'image/png'], 'acme/uploads/'.self::INTENT, new \DateTimeImmutable('2027-03-15 10:15:00+00:00'));
    }

    private function pendingMedia(): MediaObject
    {
        return MediaObject::upload(new MediaId(self::MEDIA), new OrganizationId(self::ACME_FOODS), 'acme/uploads/'.self::INTENT, 'image/png', self::LARGE_MAX_BYTES - 1, new UserId(self::MIA), new \DateTimeImmutable('2027-03-15 10:08:00+00:00'));
    }

    private function save(UploadIntent|MediaObject $entity): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($entity): void {
            $this->entityManager()->persist($entity);
            $this->entityManager()->flush();
        });
    }

    private function findIntent(): ?UploadIntent
    {
        return $this->entityManager()->find(UploadIntent::class, ['organizationId' => self::ACME_FOODS, 'id' => self::INTENT]);
    }

    private function findMedia(): ?MediaObject
    {
        return $this->entityManager()->find(MediaObject::class, ['organizationId' => self::ACME_FOODS, 'id' => self::MEDIA]);
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
