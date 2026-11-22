<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application;

use App\Review\Api\CopiedDraftScreenshot;
use App\Review\Api\RoundRefused;
use App\Review\Application\Checklist\UseCaseChecklistCommands;
use App\Review\Application\ReportPictures;
use App\Review\Application\UseCaseRoundCommands;
use App\Review\Infrastructure\Doctrine\DbalChecklistQueries;
use App\Review\Infrastructure\Doctrine\DbalChecklists;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Unit\Conversation\Application\InMemoryPingClearing;
use App\Tests\Unit\Review\Application\InMemoryUploadedMedia;
use App\Tests\Unit\Review\Application\RecordingReviewEvents;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Pin this in my draft" through UseCaseRoundCommands::copyScreenshotToDraft on the real DbalRounds, as pinround_app
 * under row-level security (ADR-0044, 2026-11-22 note). On SHOP-1, Lena's report is Round 0 and Omar's shared self-check
 * Round 1: its first screenshot is a chain of two tries (positions 0 and 2), its second one picture (position 1), so by
 * latest try the second would come first. Omar also keeps an unshared draft, and another organization has a shared
 * round with the same ticket id. Mia, the tester, copies. Each copy commits, so a write a refusal slipped past would
 * stay; the rows are removed through the migrator. Every time is a fixed one in March 2027.
 */
final class UseCaseRoundCommandsCopyToDraftTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fca01';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000fca02';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000fca03';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000fca04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000fca05';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000fca11';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000fca12';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000fca21';
    private const string OMARS_ROUND = '0197a3c0-0000-7000-8000-0000000fca22';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-0000000fca23';
    private const string OTHER_TICKETS_ROUND = '0197a3c0-0000-7000-8000-0000000fca24';
    private const string OTHER_ORGANIZATION_ROUND = '0197a3c0-0000-7000-8000-0000000fca25';
    private const string DELETED_DRAFT = '0197a3c0-0000-7000-8000-0000000fca26';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000fca27';
    private const string REPORT_PICTURE = '0197a3c0-0000-7000-8000-0000000fca31';
    private const string FIRST_TRY = '0197a3c0-0000-7000-8000-0000000fca32';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000fca33';
    private const string RETAKE = '0197a3c0-0000-7000-8000-0000000fca34';
    private const string OMARS_DRAFT_PICTURE = '0197a3c0-0000-7000-8000-0000000fca35';
    private const string OTHER_TICKETS_PICTURE = '0197a3c0-0000-7000-8000-0000000fca36';
    private const string OTHER_ORGANIZATION_PICTURE = '0197a3c0-0000-7000-8000-0000000fca37';
    private const string DELETED_DRAFT_PICTURE = '0197a3c0-0000-7000-8000-0000000fca38';
    private const string MIAS_OWN_PICTURE = '0197a3c0-0000-7000-8000-0000000fca39';
    private const string OMARS_PIN = '0197a3c0-0000-7000-8000-0000000fca41';
    private const string RETAKE_MEDIA = '0197a3c0-0000-7000-8000-0000000fca61';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000fca62';
    private const string REPORT_MEDIA = '0197a3c0-0000-7000-8000-0000000fca63';
    private const string OTHER_MEDIA = '0197a3c0-0000-7000-8000-0000000fca64';
    private const string CREATED_AT = '2027-03-15 10:00:00+00';
    private const string COPIED_AT = '2027-03-16 09:30:00+00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::REPORT, self::TICKET, 'report', self::LENA, 0),
            self::picture(self::ACME_FOODS, self::REPORT_PICTURE, self::REPORT, 0, self::REPORT_MEDIA),
            self::round(self::ACME_FOODS, self::OMARS_ROUND, self::TICKET, 'draft', self::OMAR, 1),
            self::picture(self::ACME_FOODS, self::FIRST_TRY, self::OMARS_ROUND, 0, self::OTHER_MEDIA),
            self::picture(self::ACME_FOODS, self::SECOND_SCREENSHOT, self::OMARS_ROUND, 1, self::SECOND_MEDIA),
            self::picture(self::ACME_FOODS, self::RETAKE, self::OMARS_ROUND, 2, self::RETAKE_MEDIA, self::FIRST_TRY),
            ['rvw_pin', [
                'organization_id' => self::ACME_FOODS, 'id' => self::OMARS_PIN, 'screenshot_id' => self::RETAKE, 'number' => 1, 'x_pct' => '40.00',
                'y_pct' => '60.00', 'note' => 'Total is right now', 'source' => 'human', 'author_id' => self::OMAR, 'created_at' => self::CREATED_AT,
            ]],
            self::round(self::ACME_FOODS, self::OMARS_DRAFT, self::TICKET, 'draft', self::OMAR, null),
            self::picture(self::ACME_FOODS, self::OMARS_DRAFT_PICTURE, self::OMARS_DRAFT, 0, self::OTHER_MEDIA),
            self::round(self::ACME_FOODS, self::OTHER_TICKETS_ROUND, self::OTHER_TICKET, 'draft', self::OMAR, 1),
            self::picture(self::ACME_FOODS, self::OTHER_TICKETS_PICTURE, self::OTHER_TICKETS_ROUND, 0, self::OTHER_MEDIA),
        ]);
        $this->roundRows()->addAllOf(self::OTHER_ORGANIZATION, [
            self::round(self::OTHER_ORGANIZATION, self::OTHER_ORGANIZATION_ROUND, self::TICKET, 'draft', self::OMAR, 1),
            self::picture(self::OTHER_ORGANIZATION, self::OTHER_ORGANIZATION_PICTURE, self::OTHER_ORGANIZATION_ROUND, 0, self::OTHER_MEDIA),
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function aCopyStartsMiasDraftWithTheSamePictureTitledByWhereItCameFromAndNoPins(): void
    {
        $copy = $this->copy(self::OMARS_ROUND, self::RETAKE);

        $draftRows = $this->miasDraftRows();
        self::assertCount(1, $draftRows, 'Mia has exactly one draft on the ticket.');
        [$draftId, $number, $sharedAt] = $draftRows[0];
        self::assertIsString($draftId);
        self::assertSame([null, null], [$number, $sharedAt], 'The draft is private and unnumbered.');
        self::assertSame(
            [[$copy->screenshotId, self::RETAKE_MEDIA, 'From round 1 · Screenshot 1', null, null, null]],
            $this->screenshotRowsOf($draftId),
            'The latest try of the first chain is Screenshot 1, though by its own position it would be the second.',
        );
        self::assertSame([], $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT p.id FROM rvw_pin p JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id WHERE s.round_id = ?', [$draftId]));
        self::assertEquals(new CopiedDraftScreenshot($draftId, $copy->screenshotId, 1, true), $copy);
        self::assertSame(
            [[self::FIRST_TRY], [self::SECOND_SCREENSHOT], [self::RETAKE]],
            $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [self::OMARS_ROUND]),
            'The shared round keeps its screenshots.',
        );
    }

    #[Test]
    public function aCopyIntoMiasDraftComesAfterHerOwnCaptureAndAReportsPictureSaysRoundZero(): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::MIAS_DRAFT, self::TICKET, 'draft', self::MIA, null),
            self::picture(self::ACME_FOODS, self::MIAS_OWN_PICTURE, self::MIAS_DRAFT, 0, self::OTHER_MEDIA),
        ]);

        $copy = $this->copy(self::REPORT, self::REPORT_PICTURE);

        self::assertEquals(new CopiedDraftScreenshot(self::MIAS_DRAFT, $copy->screenshotId, 2, false), $copy);
        self::assertSame(
            [[self::MIAS_OWN_PICTURE, self::OTHER_MEDIA, null, null, null, null], [$copy->screenshotId, self::REPORT_MEDIA, 'From round 0 · Screenshot 1', null, null, null]],
            $this->screenshotRowsOf(self::MIAS_DRAFT),
        );
        self::assertSame([[self::MIAS_DRAFT]], array_map(static fn (array $row): array => [$row[0]], $this->miasDraftRows()), 'No second draft.');
    }

    #[Test]
    public function aSecondCopyOfTheSamePictureIsRefusedAndTheDraftKeepsOne(): void
    {
        $first = $this->copy(self::OMARS_ROUND, self::SECOND_SCREENSHOT);
        self::assertSame(1, $first->screenshotNumber, 'The first copy is the draft\'s first screenshot.');
        $draftBefore = $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT version FROM rvw_round WHERE id = ?', [$first->draftRoundId]);

        $refusal = $this->refusalOfCopy(self::OMARS_ROUND, self::SECOND_SCREENSHOT);

        self::assertSame(RoundRefused::COPIED_ALREADY, $refusal?->getMessage());
        self::assertFalse($refusal->isNotFound());
        self::assertSame([[$first->screenshotId, self::SECOND_MEDIA, 'From round 1 · Screenshot 2', null, null, null]], $this->screenshotRowsOf($first->draftRoundId));
        self::assertSame($draftBefore, $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT version FROM rvw_round WHERE id = ?', [$first->draftRoundId]));
    }

    /**
     * @return iterable<string, array{string, string}> the round and screenshot ids sent
     */
    public static function screenshotsNotToCopy(): iterable
    {
        yield 'an earlier try, which only the draft\'s owner sees' => [self::OMARS_ROUND, self::FIRST_TRY];
        yield 'a screenshot of another round of the ticket' => [self::OMARS_ROUND, self::REPORT_PICTURE];
        yield 'an unknown screenshot' => [self::OMARS_ROUND, '0197a3c0-0000-7000-8000-0000000fca99'];
        yield 'a malformed screenshot id' => [self::OMARS_ROUND, 'not-a-screenshot'];
        yield 'a malformed round id' => ['not-a-round', self::RETAKE];
        yield 'an unknown round' => ['0197a3c0-0000-7000-8000-0000000fca98', self::RETAKE];
        yield 'a round of another ticket' => [self::OTHER_TICKETS_ROUND, self::OTHER_TICKETS_PICTURE];
        yield 'a round of another organization' => [self::OTHER_ORGANIZATION_ROUND, self::OTHER_ORGANIZATION_PICTURE];
        yield 'another person\'s unshared draft' => [self::OMARS_DRAFT, self::OMARS_DRAFT_PICTURE];
        yield 'a deleted draft' => [self::DELETED_DRAFT, self::DELETED_DRAFT_PICTURE];
    }

    #[Test]
    #[DataProvider('screenshotsNotToCopy')]
    public function aScreenshotOfNoSharedRoundOfTheTicketIsNotFoundAndNoDraftIsStarted(string $roundId, string $screenshotId): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            ['rvw_round', [
                'organization_id' => self::ACME_FOODS, 'id' => self::DELETED_DRAFT, 'ticket_id' => self::TICKET, 'number' => null, 'kind' => 'draft',
                'owner_id' => self::LENA, 'shared_at' => null, 'state' => 'closed', 'version' => 2, 'created_at' => self::CREATED_AT, 'deleted_at' => self::CREATED_AT,
            ]],
            self::picture(self::ACME_FOODS, self::DELETED_DRAFT_PICTURE, self::DELETED_DRAFT, 0, self::OTHER_MEDIA),
        ]);

        $refusal = $this->refusalOfCopy($roundId, $screenshotId);

        self::assertSame(RoundRefused::SHARED_SCREENSHOT_NOT_FOUND, $refusal?->getMessage());
        self::assertTrue($refusal->isNotFound());
        self::assertSame([], $this->miasDraftRows());
    }

    private function copy(string $roundId, string $screenshotId): CopiedDraftScreenshot
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): CopiedDraftScreenshot => $this->commands()->copyScreenshotToDraft(
            new TicketId(self::TICKET),
            $roundId,
            $screenshotId,
            new UserId(self::MIA),
            new \DateTimeImmutable(self::COPIED_AT),
        ));
    }

    /**
     * Catches the refusal inside the transaction, which then commits, so a write the refusal slipped past would stay.
     */
    private function refusalOfCopy(string $roundId, string $screenshotId): ?RoundRefused
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, function () use ($roundId, $screenshotId): ?RoundRefused {
            try {
                $this->commands()->copyScreenshotToDraft(new TicketId(self::TICKET), $roundId, $screenshotId, new UserId(self::MIA), new \DateTimeImmutable(self::COPIED_AT));
            } catch (RoundRefused $refused) {
                return $refused;
            }

            return null;
        });
    }

    /**
     * @return list<list<mixed>> id, number and shared at of each of Mia's drafts on the ticket
     */
    private function miasDraftRows(): array
    {
        return $this->roundRows()->runAs(self::ACME_FOODS, "SELECT id, number, shared_at FROM rvw_round WHERE owner_id = ? AND kind = 'draft' ORDER BY id", [self::MIA]);
    }

    /**
     * @return list<list<mixed>> id, media, title, summary, verdict and retake of each screenshot, by position
     */
    private function screenshotRowsOf(string $roundId): array
    {
        return $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id, media_id, title, summary, verdict, retake_of FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [$roundId]);
    }

    private function commands(): UseCaseRoundCommands
    {
        $tenantContext = $this->tenantContext();
        $rounds = new DbalRounds($this->connection(), $tenantContext);
        $ticketTurnQueries = new InMemoryTicketTurnQueries([self::ticket()], new TicketId(self::OTHER_TICKET));
        $checklistCommands = new UseCaseChecklistCommands(
            new DbalChecklists($this->connection(), $tenantContext),
            $rounds,
            new DbalChecklistQueries($this->connection(), $tenantContext),
            new RecordingReviewEvents(),
            new InMemoryPingClearing(),
            $tenantContext,
            $ticketTurnQueries,
        );

        // No uploaded media: a copy points to a picture Mia did not upload, so a check of who uploaded it would refuse.
        return new UseCaseRoundCommands($rounds, $ticketTurnQueries, $tenantContext, new ReportPictures(new InMemoryUploadedMedia($tenantContext, [])), $checklistCommands);
    }

    private static function ticket(): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-0000000fca71',
            '0197a3c0-0000-7000-8000-0000000fca72',
            'test',
            'check',
            null,
            null,
            null,
            [],
            [new UserId(self::MIA)],
            'all',
            'major',
            null,
            new UserId(self::LENA),
            assigneeId: new UserId(self::OMAR),
            testerId: new UserId(self::MIA),
        );
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $organizationId, string $roundId, string $ticketId, string $kind, string $ownerId, ?int $number): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId, 'id' => $roundId, 'ticket_id' => $ticketId, 'number' => $number, 'kind' => $kind,
            'owner_id' => $ownerId, 'shared_at' => null === $number ? null : self::CREATED_AT, 'state' => 'open', 'version' => 1, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function picture(string $organizationId, string $screenshotId, string $roundId, int $position, string $mediaId, ?string $retakeOf = null): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId, 'id' => $screenshotId, 'round_id' => $roundId, 'media_id' => $mediaId,
            'position' => $position, 'retake_of' => $retakeOf, 'created_at' => self::CREATED_AT,
        ]];
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
