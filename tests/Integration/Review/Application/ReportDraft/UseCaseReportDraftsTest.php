<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application\ReportDraft;

use App\Media\Api\MediaImageStatus;
use App\Review\Api\AddedReportDraftScreenshot;
use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDraftView;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\DraftRoundAsRead;
use App\Review\Application\DraftRoundContents;
use App\Review\Application\PicturesToKeep;
use App\Review\Application\ReportDraft\UseCaseReportDrafts;
use App\Review\Infrastructure\Doctrine\DbalReportDraftRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Review\Application\InMemoryUploadedMedia;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\WhereItGoesRules;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * UseCaseReportDrafts::addScreenshot and createFromDraftRound on the real rvw_report_draft as pinround_app, under
 * row-level security; the uploads are InMemoryUploadedMedia and the draft round InMemoryDraftRoundContents. Every time
 * is a fixed one in March 2027; nothing reads the clock. Each change is committed, or rolled back by its refusal,
 * before the draft is read back.
 */
final class UseCaseReportDraftsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6201';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6203';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6204';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f6211';
    private const string MIAS_DRAFT_ROUND = '0197a3c0-0000-7000-8000-0000000f6221';
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f6231';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f6241';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f6251';
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

        $added = $this->addAs(self::MIA, new ScreenshotWithPins(strtoupper(self::mediaId(3)), 'Third', null, []));

        self::assertEquals(new AddedReportDraftScreenshot(false, 3), $added);
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

        $added = $this->addAs(self::MIA, new ScreenshotWithPins(strtoupper(self::mediaId(1)), 'Another title', 'Another summary', []));

        self::assertEquals(new AddedReportDraftScreenshot(true, 2), $added);
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
        $added = $this->addAs(self::MIA, new ScreenshotWithPins(self::mediaId(10), null, null, []));
        self::assertEquals(new AddedReportDraftScreenshot(true, 10), $added);
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

    /**
     * ADR-0044: the second picture is the first with a title, and the pins keep their order; the round is deleted once.
     */
    #[Test]
    public function aDraftRoundMovesIntoAReportDraftOfItsTicketsProjectAndASecondMoveIsNotFound(): void
    {
        $contents = $this->miasDraftRound([
            new ScreenshotWithPins(self::mediaId(2), '  ', 'What the tester saw', [new PlacedPin(12.5, 20.75, 'First pin'), new PlacedPin(40.25, 60.5, 'Second pin')]),
            new ScreenshotWithPins(self::mediaId(1), 'Cart total', null, []),
        ]);

        $reportDraftId = $this->moveAs(self::MIA, $contents);

        $draft = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?ReportDraftView => $this->rows()->ofOwner($reportDraftId, new UserId(self::MIA)));
        self::assertNotNull($draft);
        self::assertSame(['SHOP', 'Cart total', 'Found while testing SHOP-7', 1], [$draft->projectKey, $draft->title, $draft->detail, $draft->version]);
        self::assertEquals(
            [
                new ScreenshotWithPins(self::mediaId(2), null, 'What the tester saw', [new PlacedPin(12.5, 20.75, 'First pin'), new PlacedPin(40.25, 60.5, 'Second pin')]),
                new ScreenshotWithPins(self::mediaId(1), 'Cart total', null, []),
            ],
            $draft->screenshots,
        );
        self::assertEquals([[self::MIAS_DRAFT_ROUND, new \DateTimeImmutable(self::ADDED)]], $contents->deletions);

        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOfMoveAs(self::MIA, $contents));
        self::assertSame([$reportDraftId], $this->reportDraftIds(), 'A second move makes no second report draft.');
        self::assertCount(1, $contents->deletions);
    }

    #[Test]
    public function aDraftRoundWithNoPictureTitleIsTitledWithTheLineItWasFoundOn(): void
    {
        $contents = $this->miasDraftRound([new ScreenshotWithPins(self::mediaId(1), null, null, [])]);

        $reportDraftId = $this->moveAs(self::MIA, $contents);

        $draft = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?ReportDraftView => $this->rows()->ofOwner($reportDraftId, new UserId(self::MIA)));
        self::assertNotNull($draft);
        self::assertSame(['Found while testing SHOP-7', 'Found while testing SHOP-7'], [$draft->title, $draft->detail]);
    }

    /**
     * @return iterable<string, array{list<ScreenshotWithPins>, string}>
     */
    public static function draftRoundsAReportDraftCannotHold(): iterable
    {
        yield 'eleven pictures' => [array_map(static fn (int $number): ScreenshotWithPins => new ScreenshotWithPins(self::mediaId($number), 'Picture '.$number, null, []), range(1, 11)), 'A report holds at most 10 screenshots.'];
        yield 'a picture Mia did not upload' => [[new ScreenshotWithPins(self::mediaId(1), null, null, []), new ScreenshotWithPins(self::EXTRA_OF_OMAR, null, null, [])], RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    /**
     * Refused before the round is deleted, not by rolling the delete back: the round is never deleted at all.
     *
     * @param list<ScreenshotWithPins> $screenshots
     */
    #[Test]
    #[DataProvider('draftRoundsAReportDraftCannotHold')]
    public function aDraftRoundAReportDraftCannotHoldIsRefusedBeforeTheRoundIsDeleted(array $screenshots, string $reason): void
    {
        $contents = $this->miasDraftRound($screenshots);

        self::assertSame($reason, $this->refusalOfMoveAs(self::MIA, $contents));
        self::assertSame([], $contents->deletions);
        self::assertSame([], $this->reportDraftIds());
    }

    #[Test]
    public function aDraftRoundAnotherRequestSavedAfterItWasReadIsRefusedAsChangedAndNoReportDraftIsSaved(): void
    {
        $contents = $this->miasDraftRound([new ScreenshotWithPins(self::mediaId(1), 'Cart total', null, [])]);
        $contents->isChangedAfterNextRead = true;

        self::assertSame(RoundRefused::DRAFT_CHANGED, $this->refusalOfMoveAs(self::MIA, $contents));
        self::assertSame([], $contents->deletions);
        self::assertSame([], $this->reportDraftIds());
    }

    #[Test]
    public function anotherPersonsDraftRoundIsNotFoundAndNothingIsSaved(): void
    {
        $contents = $this->miasDraftRound([new ScreenshotWithPins(self::mediaId(1), 'Cart total', null, [])]);

        self::assertSame(RoundRefused::NOT_FOUND, $this->refusalOfMoveAs(self::OMAR, $contents));
        self::assertSame([], $contents->deletions);
        self::assertSame([], $this->reportDraftIds());
    }

    /**
     * @param list<ScreenshotWithPins> $screenshots
     */
    private function miasDraftRound(array $screenshots): InMemoryDraftRoundContents
    {
        return new InMemoryDraftRoundContents(new DraftRoundAsRead(self::MIAS_DRAFT_ROUND, new TicketId(self::SHOP_7), 4, $screenshots), new UserId(self::MIA));
    }

    private function moveAs(string $owner, InMemoryDraftRoundContents $contents): string
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->reportDrafts($contents)->createFromDraftRound(self::MIAS_DRAFT_ROUND, new UserId($owner), new \DateTimeImmutable(self::ADDED)));
    }

    private function refusalOfMoveAs(string $owner, InMemoryDraftRoundContents $contents): string
    {
        try {
            $this->moveAs($owner, $contents);
        } catch (RoundRefused|ReportDraftRefused $refused) {
            // The rollback closed the entity manager; the next transaction needs a fresh one, as a new request has.
            self::getContainer()->get('doctrine')->resetManager();

            return $refused->getMessage();
        }
        self::fail('The move was accepted.');
    }

    /**
     * @return list<string> the organization's report drafts, by id
     */
    private function reportDraftIds(): array
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $ids = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => $connection->fetchFirstColumn('SELECT id FROM rvw_report_draft WHERE organization_id = ? ORDER BY id', [self::ACME_FOODS]));

        return array_map(static function (mixed $id): string {
            self::assertIsString($id);

            return $id;
        }, $ids);
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
    private function addAs(string $owner, ScreenshotWithPins $screenshot): AddedReportDraftScreenshot
    {
        try {
            return $this->transactions()->committedAs(self::ACME_FOODS, fn (): AddedReportDraftScreenshot => $this->reportDrafts()->addScreenshot(self::MIAS_DRAFT, new UserId($owner), $screenshot, new \DateTimeImmutable(self::ADDED)));
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

    private function reportDrafts(?DraftRoundContents $draftRoundContents = null): UseCaseReportDrafts
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $uploads = array_map(static fn (int $number): array => [self::ACME_FOODS, self::mediaId($number), self::MIA, MediaImageStatus::Ready], range(1, 12));
        $uploads[] = [self::ACME_FOODS, self::EXTRA_OF_OMAR, self::OMAR, MediaImageStatus::Ready];
        $projects = self::createStub(ProjectQueries::class);
        $projects->method('projectByKey')->willReturnCallback(static fn (string $key): ?ProjectChoice => 'SHOP' === $key ? new ProjectChoice(self::SHOP_PROJECT, 'SHOP', 'Shop', new TeamId(self::SHOP_TEAM)) : null);
        $ticketKeys = self::createStub(TicketKeyQueries::class);
        $ticketKeys->method('keyOf')->willReturnCallback(static fn (TicketId $ticketId): ?string => $ticketId->equals(new TicketId(self::SHOP_7)) ? 'SHOP-7' : null);

        return new UseCaseReportDrafts(
            $this->rows(),
            $projects,
            self::createStub(TicketCommands::class),
            $ticketKeys,
            self::createStub(RoundCommands::class),
            new PicturesToKeep(new InMemoryUploadedMedia($tenantContext, $uploads)),
            $tenantContext,
            $draftRoundContents ?? self::createStub(DraftRoundContents::class),
            self::createStub(WhereItGoesRules::class),
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
