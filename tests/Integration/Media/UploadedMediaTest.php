<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Api\UploadedMedia;
use App\Media\Api\UploadedMediaState;
use App\Media\Application\UploaderMediaStates;
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
 * UploadedMedia as the container wires it, on the real med_object table as pinround_app under row-level security.
 * Acme Foods holds a pending, a ready and a rejected picture of Mia and a ready one of Omar; another organization holds
 * a ready one of Mia.
 */
final class UploadedMediaTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6201';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f6202';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6203';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6204';
    private const string MIA_PENDING = '0197a3c0-0000-7000-8000-0000000f6211';
    private const string MIA_READY = '0197a3c0-0000-7000-8000-0000000f6212';
    private const string MIA_REJECTED = '0197a3c0-0000-7000-8000-0000000f6213';
    private const string OMAR_READY = '0197a3c0-0000-7000-8000-0000000f6214';
    private const string MIA_AT_OTHER = '0197a3c0-0000-7000-8000-0000000f6215';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-0000000f6219';

    protected function setUp(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->addMedia(self::ACME_FOODS, self::MIA_PENDING, self::MIA, 'pending');
        $this->addMedia(self::ACME_FOODS, self::MIA_READY, self::MIA, 'ready');
        $this->addMedia(self::ACME_FOODS, self::MIA_REJECTED, self::MIA, 'rejected');
        $this->addMedia(self::ACME_FOODS, self::OMAR_READY, self::OMAR, 'ready');
        $this->addMedia(self::OTHER_ORGANIZATION, self::MIA_AT_OTHER, self::MIA, 'ready');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function theApiIsTheUploaderMediaStates(): void
    {
        self::assertInstanceOf(UploaderMediaStates::class, self::getContainer()->get('test.media_uploaded_media'));
    }

    #[Test]
    public function miaSeesTheStateOfEachOfHerUploadsAndNothingOfOmarsOrAnotherOrganizationsOrAnUnknownOne(): void
    {
        $askedIds = [self::MIA_PENDING, self::MIA_READY, self::MIA_REJECTED, self::OMAR_READY, self::MIA_AT_OTHER, self::UNKNOWN, 'not-an-id'];
        $debugData = $this->debugData();

        [$states, $queryCount] = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($askedIds, $debugData): array {
            $debugData->reset();
            $states = $this->statesOf(self::MIA, $askedIds);

            return [$states, self::queryCountOf($debugData)];
        });

        self::assertEquals(
            [self::MIA_PENDING => UploadedMediaState::Pending, self::MIA_READY => UploadedMediaState::Ready, self::MIA_REJECTED => UploadedMediaState::Rejected],
            $states,
        );
        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function omarSeesOnlyHisOwnUpload(): void
    {
        $states = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->statesOf(self::OMAR, [self::MIA_READY, self::OMAR_READY]));

        self::assertSame([self::OMAR_READY => UploadedMediaState::Ready], $states);
    }

    #[Test]
    public function rowLevelSecurityAloneLeavesOutAnotherOrganizationsUploadWithTheTenantFilterOff(): void
    {
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $states = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->statesOf(self::MIA, [self::MIA_AT_OTHER, self::MIA_READY]));
        $asTheOther = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->uploadedMedia()->statesOf(new OrganizationId(self::OTHER_ORGANIZATION), new UserId(self::MIA), [self::MIA_AT_OTHER, self::MIA_READY]));

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame([self::MIA_READY], array_keys($states));
        self::assertSame([self::MIA_AT_OTHER], array_keys($asTheOther), 'The other organization does not see its own upload either, so the check above proves nothing.');
    }

    #[Test]
    public function fiftyOneIdsAreRefusedAndFiftyAreRead(): void
    {
        $fifty = array_fill(0, UploadedMedia::MAX_MEDIA_IDS, self::MIA_READY);

        $states = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->statesOf(self::MIA, $fifty));

        self::assertSame([self::MIA_READY], array_keys($states));
        $this->expectException(\InvalidArgumentException::class);
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->statesOf(self::MIA, [...$fifty, self::MIA_READY]));
    }

    #[Test]
    public function aTenantThatIsAnotherOrganizationIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->statesOf(self::MIA, [self::MIA_READY]));
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<string, UploadedMediaState>
     */
    private function statesOf(string $uploader, array $mediaIds): array
    {
        return $this->uploadedMedia()->statesOf(new OrganizationId(self::ACME_FOODS), new UserId($uploader), $mediaIds);
    }

    private function addMedia(string $organizationId, string $mediaId, string $uploader, string $status): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $mediaId, $uploader, $status): void {
            $mediaObject = MediaObject::upload(new MediaId($mediaId), new OrganizationId($organizationId), 'uploads/'.$organizationId.'/'.$mediaId, 'image/png', 2048, new UserId($uploader), new \DateTimeImmutable('2026-04-07 09:00:00 UTC'));
            if ('ready' === $status) {
                $mediaObject->markReady(hash('sha256', $mediaId), 1280, 800, ['view' => 'media/'.$mediaId.'/view.webp']);
            }
            if ('rejected' === $status) {
                $mediaObject->reject(MediaRejectReason::Unreadable->value);
            }
            new DoctrineMediaObjects($this->entityManager())->add($mediaObject);
        });
    }

    private function uploadedMedia(): UploadedMedia
    {
        $uploadedMedia = self::getContainer()->get('test.media_uploaded_media');
        \assert($uploadedMedia instanceof UploadedMedia);

        return $uploadedMedia;
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
