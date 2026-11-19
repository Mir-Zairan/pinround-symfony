<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application\ReportDraft;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDraftView;
use App\Review\Api\RoundCommands;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\ReportDraft\UseCaseReportDrafts;
use App\Review\Infrastructure\Doctrine\DbalReportDraftRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Review\Application\InMemoryUploadedMedia;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UseCaseReportDrafts::addScreenshot on the real rvw_report_draft as pinround_app, under row-level security; the
 * uploads are InMemoryUploadedMedia. Every time is a fixed one in March 2027; nothing reads the clock. Each add is
 * committed, or rolled back by its refusal, before the draft is read back.
 */
final class UseCaseReportDraftsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6201';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6203';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6204';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f6211';
    /** Media ids end in 01 to 12; Mia uploaded each, Omar uploaded EXTRA_OF_OMAR. */
    private const string MEDIA_PREFIX = '0197a3c0-0000-7000-8000-0000000f63';
    private const string EXTRA_OF_OMAR = '0197a3c0-0000-7000-8000-0000000f6399';
    private const string CREATED = '2027-03-15 11:20:30+01:00';
    private const string ADDED = '2027-03-16 09:00:00+01:00';

    protected function setUp(): void
    {
        $this->removeDrafts();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeDrafts();
        parent::tearDown();
    }

    #[Test]
    public function aNewPictureGoesAtTheEndAndTheVersionGoesUp(): void
    {
        $this->insertMiasDraft(2);

        $this->addAs(self::MIA, new ScreenshotWithPins(strtoupper(self::mediaId(3)), 'Third', null, []));

        $draft = $this->miasDraft();
        self::assertSame([self::mediaId(1), self::mediaId(2), self::mediaId(3)], array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $draft->screenshots));
        self::assertSame(['Third', 2], [$draft->screenshots[2]->title, $draft->version]);
        self::assertEquals(new \DateTimeImmutable(self::ADDED), $draft->updatedAt);
    }

    /**
     * The same picture again, in another case and with another title, is a no-op: neither its text nor the version
     * changes, so an extension that retries an add saves nothing twice.
     */
    #[Test]
    public function addingAPictureTheDraftHoldsAlreadyChangesNothing(): void
    {
        $this->insertMiasDraft(2);
        $before = $this->miasDraft();

        $this->addAs(self::MIA, new ScreenshotWithPins(strtoupper(self::mediaId(1)), 'Another title', 'Another summary', []));

        self::assertEquals($before, $this->miasDraft());
    }

    #[Test]
    public function anEleventhPictureIsRefusedAndNothingIsSaved(): void
    {
        $this->insertMiasDraft(10);
        $before = $this->miasDraft();
        self::assertCount(10, $before->screenshots);

        $refusal = null;
        try {
            $this->addAs(self::MIA, new ScreenshotWithPins(self::mediaId(11), 'Eleventh', null, []));
        } catch (ReportDraftRefused $refused) {
            $refusal = $refused->getMessage();
        }

        self::assertSame(ReportDraftRefused::TOO_MANY_SCREENSHOTS, $refusal);
        self::assertEquals($before, $this->miasDraft());
        $this->addAs(self::MIA, new ScreenshotWithPins(self::mediaId(10), null, null, []));
        self::assertEquals($before, $this->miasDraft(), 'One of the ten again is a no-op, not a refusal, on a full draft.');
    }

    #[Test]
    public function anotherPersonsDraftIsNotFoundAndNothingIsSaved(): void
    {
        $this->insertMiasDraft(1);
        $before = $this->miasDraft();

        $isNotFound = false;
        try {
            $this->addAs(self::OMAR, new ScreenshotWithPins(self::EXTRA_OF_OMAR, null, null, []));
        } catch (ReportDraftRefused $refused) {
            $isNotFound = $refused->isNotFound();
        }

        self::assertTrue($isNotFound);
        self::assertEquals($before, $this->miasDraft());
    }

    private static function mediaId(int $number): string
    {
        return self::MEDIA_PREFIX.\sprintf('%02d', $number);
    }

    private function insertMiasDraft(int $screenshotCount): void
    {
        $screenshots = array_map(static fn (int $number): ScreenshotWithPins => new ScreenshotWithPins(self::mediaId($number), 'Picture '.$number, null, []), range(1, $screenshotCount));
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($screenshots): void {
            $this->rows()->insert(new OrganizationId(self::ACME_FOODS), self::MIAS_DRAFT, new UserId(self::MIA), 'SHOP', new ReportDraftContent('Total is doubled', '', $screenshots), new \DateTimeImmutable(self::CREATED));
        });
    }

    /**
     * @throws ReportDraftRefused after its transaction rolled back
     */
    private function addAs(string $owner, ScreenshotWithPins $screenshot): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, function () use ($owner, $screenshot): void {
                $this->reportDrafts()->addScreenshot(self::MIAS_DRAFT, new UserId($owner), $screenshot, new \DateTimeImmutable(self::ADDED));
            });
        } catch (ReportDraftRefused $refused) {
            // The rollback closed the entity manager; the next transaction needs a fresh one, as a new request has.
            self::getContainer()->get('doctrine')->resetManager();

            throw $refused;
        }
    }

    private function miasDraft(): ReportDraftView
    {
        $draft = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?ReportDraftView => $this->rows()->ofOwner(self::MIAS_DRAFT, new UserId(self::MIA)));
        self::assertNotNull($draft);

        return $draft;
    }

    private function reportDrafts(): UseCaseReportDrafts
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $uploads = array_map(static fn (int $number): array => [self::ACME_FOODS, self::mediaId($number), self::MIA, MediaImageStatus::Ready], range(1, 12));
        $uploads[] = [self::ACME_FOODS, self::EXTRA_OF_OMAR, self::OMAR, MediaImageStatus::Ready];

        return new UseCaseReportDrafts(
            $this->rows(),
            self::createStub(ProjectQueries::class),
            self::createStub(TicketCommands::class),
            self::createStub(TicketKeyQueries::class),
            self::createStub(RoundCommands::class),
            new InMemoryUploadedMedia($tenantContext, $uploads),
            $tenantContext,
        );
    }

    private function removeDrafts(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $migrator->transactional(static fn (Connection $connection): int|string => $connection->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [self::ACME_FOODS]));
        } finally {
            $tenantContext->clear();
        }
    }

    private function rows(): DbalReportDraftRows
    {
        return new DbalReportDraftRows(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
