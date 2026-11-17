<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\RoundRefused;
use App\Review\Application\ReportPictures;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ReportPictures under the Acme Foods tenant. Lena has a ready, a pending and a rejected picture there; Omar's picture
 * is ready there and Lena's own one is ready in Beta Bakery.
 */
final class ReportPicturesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000f02';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string READY_MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string OTHER_READY_MEDIA = '0197a3c0-0000-7000-8000-000000000d02';
    private const string PENDING_MEDIA = '0197a3c0-0000-7000-8000-000000000d03';
    private const string REJECTED_MEDIA = '0197a3c0-0000-7000-8000-000000000d04';
    private const string OMARS_MEDIA = '0197a3c0-0000-7000-8000-000000000d05';
    private const string LENAS_MEDIA_AT_BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000d06';

    private InMemoryUploadedMedia $uploadedMedia;
    private ReportPictures $reportPictures;

    protected function setUp(): void
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->uploadedMedia = new InMemoryUploadedMedia($tenantContext, [
            [self::ACME_FOODS, self::READY_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::OTHER_READY_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::PENDING_MEDIA, self::LENA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::REJECTED_MEDIA, self::LENA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_MEDIA, self::OMAR, MediaImageStatus::Ready],
            [self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, self::LENA, MediaImageStatus::Ready],
        ]);
        $this->reportPictures = new ReportPictures($this->uploadedMedia);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function picturesAndTheirAnswers(): iterable
    {
        yield 'the reporter\'s ready picture' => [self::READY_MEDIA, null];
        yield 'the reporter\'s ready picture in upper case' => [strtoupper(self::READY_MEDIA), null];
        yield 'the reporter\'s picture still processing' => [self::PENDING_MEDIA, RoundRefused::MEDIA_STILL_PROCESSING];
        yield 'the reporter\'s rejected picture' => [self::REJECTED_MEDIA, RoundRefused::MEDIA_REJECTED];
        yield 'another person\'s ready picture' => [self::OMARS_MEDIA, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'the reporter\'s picture in another organization' => [self::LENAS_MEDIA_AT_BETA_BAKERY, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
        yield 'a malformed media id' => ['not-a-media-id', RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    #[Test]
    #[DataProvider('picturesAndTheirAnswers')]
    public function theRefusalTellsWhetherThePictureIsTheReportersAndProcessed(string $mediaId, ?string $refusal): void
    {
        self::assertSame($refusal, $this->reportPictures->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [$mediaId]));
        self::assertSame([[$mediaId]], $this->uploadedMedia->askedMediaIds);
    }

    /**
     * Ready pictures before and after do not hide it, and the first refused one in the list names the reason.
     */
    #[Test]
    public function theFirstRefusedPictureInTheListNamesTheReasonAfterOneRead(): void
    {
        $mediaIds = [self::READY_MEDIA, self::REJECTED_MEDIA, self::PENDING_MEDIA, self::OTHER_READY_MEDIA];

        self::assertSame(RoundRefused::MEDIA_REJECTED, $this->reportPictures->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), $mediaIds));
        self::assertSame([$mediaIds], $this->uploadedMedia->askedMediaIds);
    }

    #[Test]
    public function severalReadyPicturesOfTheReporterMayAllGoIn(): void
    {
        self::assertNull($this->reportPictures->refusalOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [self::READY_MEDIA, self::OTHER_READY_MEDIA]));
    }

    /**
     * Lena's Beta Bakery picture is ready for her there, so only the tenant check keeps it from being answered as fine.
     */
    #[Test]
    public function aPictureAskedUnderAnotherOrganizationThanTheTenantsFailsBeforeAnyRead(): void
    {
        try {
            $this->reportPictures->refusalOf(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), [self::LENAS_MEDIA_AT_BETA_BAKERY]);
            self::fail('A picture of another organization than the tenant\'s was answered.');
        } catch (\LogicException $wrongTenant) {
            self::assertSame('An upload state is read under the tenant of its own organization.', $wrongTenant->getMessage());
        }

        self::assertSame([], $this->uploadedMedia->askedMediaIds);
    }
}
