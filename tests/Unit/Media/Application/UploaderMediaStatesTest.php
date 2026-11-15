<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Api\UploadedMedia;
use App\Media\Api\UploadedMediaState;
use App\Media\Application\UploaderMediaStates;
use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaRejectReason;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UploaderMediaStates over the in-memory media objects. Mia uploaded a pending, a ready and a rejected picture of Acme
 * Foods; Omar uploaded one there; Mia also has one at another organization.
 */
final class UploaderMediaStatesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6101';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f6102';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6103';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6104';
    private const string MIA_PENDING = '0197a3c0-0000-7000-8000-0000000f6111';
    private const string MIA_READY = '0197a3c0-0000-7000-8000-0000000f6112';
    private const string MIA_REJECTED = '0197a3c0-0000-7000-8000-0000000f6113';
    private const string OMAR_READY = '0197a3c0-0000-7000-8000-0000000f6114';
    private const string MIA_AT_OTHER = '0197a3c0-0000-7000-8000-0000000f6115';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-0000000f6119';

    private TenantContext $tenantContext;
    private InMemoryTransactions $transactions;
    private InMemoryMediaObjects $mediaObjects;
    private UploaderMediaStates $uploadedMedia;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->transactions = new InMemoryTransactions($this->tenantContext);
        $this->mediaObjects = new InMemoryMediaObjects($this->transactions, $this->tenantContext);
        $this->uploadedMedia = new UploaderMediaStates($this->mediaObjects, $this->tenantContext);
        $this->seed();
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
    }

    #[Test]
    public function miaSeesThePendingReadyAndRejectedStateOfHerOwnUploads(): void
    {
        $states = $this->statesOf(self::MIA, [self::MIA_PENDING, self::MIA_READY, self::MIA_REJECTED]);

        self::assertEqualsCanonicalizing(
            [self::MIA_PENDING => UploadedMediaState::Pending, self::MIA_READY => UploadedMediaState::Ready, self::MIA_REJECTED => UploadedMediaState::Rejected],
            $states,
        );
        self::assertCount(3, $states);
    }

    #[Test]
    public function anotherPersonsUploadAnotherOrganizationsUploadAnUnknownIdAndAMalformedIdAreLeftOut(): void
    {
        $states = $this->statesOf(self::MIA, [self::OMAR_READY, self::MIA_AT_OTHER, self::UNKNOWN, 'not-an-id', '', self::MIA_READY]);

        self::assertSame([self::MIA_READY => UploadedMediaState::Ready], $states);
    }

    #[Test]
    public function anIdInCapitalsAndAnIdGivenTwiceAreAnsweredOnceUnderTheLowercaseId(): void
    {
        $states = $this->statesOf(self::MIA, [strtoupper(self::MIA_READY), self::MIA_READY]);

        self::assertSame([self::MIA_READY => UploadedMediaState::Ready], $states);
        self::assertSame([[self::MIA_READY]], $this->mediaObjects->byIdsReads());
    }

    #[Test]
    public function noIdsAreReadInOneQueryAtMostAndFiftyIdsAreAccepted(): void
    {
        self::assertSame([], $this->statesOf(self::MIA, []));
        $fiftyIds = array_fill(0, UploadedMedia::MAX_MEDIA_IDS, self::MIA_READY);

        self::assertSame([self::MIA_READY => UploadedMediaState::Ready], $this->statesOf(self::MIA, $fiftyIds));
    }

    #[Test]
    public function moreThanFiftyIdsAreRefusedBeforeAnyRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 50');

        try {
            $this->statesOf(self::MIA, array_fill(0, UploadedMedia::MAX_MEDIA_IDS + 1, self::MIA_READY));
        } finally {
            self::assertSame([], $this->mediaObjects->byIdsReads());
        }
    }

    #[Test]
    public function aTenantThatIsAnotherOrganizationIsRefusedBeforeAnyRead(): void
    {
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));

        try {
            $this->transactions->run(fn (): array => $this->uploadedMedia->statesOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [self::MIA_READY]));
            self::fail('A tenant of another organization must be refused.');
        } catch (\LogicException) {
            self::assertSame([], $this->mediaObjects->byIdsReads());
        }
    }

    #[Test]
    public function noTenantIsRefusedBeforeAnyRead(): void
    {
        $this->tenantContext->clear();

        $this->expectException(\LogicException::class);

        $this->uploadedMedia->statesOf(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), [self::MIA_READY]);
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, UploadedMediaState>
     */
    private function statesOf(string $uploader, array $mediaIds): array
    {
        return $this->transactions->run(fn (): array => $this->uploadedMedia->statesOf(new OrganizationId(self::ACME_FOODS), new UserId($uploader), $mediaIds));
    }

    private function seed(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->transactions->run(function (): void {
            $this->mediaObjects->add(self::uploaded(self::ACME_FOODS, self::MIA_PENDING, self::MIA));
            $ready = self::uploaded(self::ACME_FOODS, self::MIA_READY, self::MIA);
            $ready->markReady(hash('sha256', 'ready'), 1280, 800, ['view' => 'media/ready/view.webp']);
            $this->mediaObjects->add($ready);
            $rejected = self::uploaded(self::ACME_FOODS, self::MIA_REJECTED, self::MIA);
            $rejected->reject(MediaRejectReason::NotAnImage->value);
            $this->mediaObjects->add($rejected);
            $this->mediaObjects->add(self::uploaded(self::ACME_FOODS, self::OMAR_READY, self::OMAR));
        });
        $this->tenantContext->set(new OrganizationId(self::OTHER_ORGANIZATION));
        $this->transactions->run(fn () => $this->mediaObjects->add(self::uploaded(self::OTHER_ORGANIZATION, self::MIA_AT_OTHER, self::MIA)));
    }

    private static function uploaded(string $organizationId, string $mediaId, string $uploader): MediaObject
    {
        return MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), 'uploads/'.$mediaId, 'image/png', 2048, new UserId($uploader), new \DateTimeImmutable('2026-04-07 09:00:00 UTC'));
    }
}
