<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\RoundRefused;
use App\Review\Application\PicturesToKeep;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PicturesToKeep under the Acme Foods tenant. Lena has a ready, a pending and a rejected picture there; Omar's picture
 * is ready there and Lena's own one is ready in Beta Bakery.
 */
final class PicturesToKeepTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f11';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000f12';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b12';
    private const string READY_MEDIA = '0197a3c0-0000-7000-8000-000000000d11';
    private const string PENDING_MEDIA = '0197a3c0-0000-7000-8000-000000000d12';
    private const string REJECTED_MEDIA = '0197a3c0-0000-7000-8000-000000000d13';
    private const string OMARS_MEDIA = '0197a3c0-0000-7000-8000-000000000d14';
    private const string LENAS_MEDIA_AT_BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000d15';

    private InMemoryUploadedMedia $uploadedMedia;
    private PicturesToKeep $picturesToKeep;

    protected function setUp(): void
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->uploadedMedia = new InMemoryUploadedMedia($tenantContext, [
            [self::ACME_FOODS, self::READY_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::PENDING_MEDIA, self::LENA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::REJECTED_MEDIA, self::LENA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_MEDIA, self::OMAR, MediaImageStatus::Ready],
            [self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, self::LENA, MediaImageStatus::Ready],
        ]);
        $this->picturesToKeep = new PicturesToKeep($this->uploadedMedia);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function picturesAndTheirAnswers(): iterable
    {
        yield 'her ready picture' => [self::READY_MEDIA, null];
        yield 'her picture still processing' => [self::PENDING_MEDIA, null];
        yield 'her picture still processing, in capitals' => [strtoupper(self::PENDING_MEDIA), null];
        yield 'her rejected picture' => [self::REJECTED_MEDIA, RoundRefused::MEDIA_REJECTED];
        yield 'her rejected picture, in capitals' => [strtoupper(self::REJECTED_MEDIA), RoundRefused::MEDIA_REJECTED];
        yield 'another person\'s ready picture' => [self::OMARS_MEDIA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'her picture in another organization' => [self::LENAS_MEDIA_AT_BETA_BAKERY, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a malformed media id' => ['not-a-media-id', RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    #[Test]
    #[DataProvider('picturesAndTheirAnswers')]
    public function theRefusalTellsWhetherThePictureIsHerOwnAndNotRejected(string $mediaId, ?string $refusal): void
    {
        self::assertSame($refusal, $this->picturesToKeep->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [$mediaId]));
        self::assertSame([[$mediaId]], $this->uploadedMedia->askedMediaIds);
    }

    /**
     * Pictures she may keep before and after do not hide it, and the first refused one in the list names the reason.
     */
    #[Test]
    public function theFirstRefusedPictureInTheListNamesTheReasonAfterOneRead(): void
    {
        $mediaIds = [self::READY_MEDIA, self::PENDING_MEDIA, self::OMARS_MEDIA, self::REJECTED_MEDIA];

        self::assertSame(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU, $this->picturesToKeep->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $mediaIds));
        self::assertSame([$mediaIds], $this->uploadedMedia->askedMediaIds);
    }

    #[Test]
    public function noPicturesAreKeptWithoutARead(): void
    {
        self::assertNull($this->picturesToKeep->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), []));
        self::assertSame([], $this->uploadedMedia->askedMediaIds);
    }
}
