<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Application;

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
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sharing a draft round through UseCaseRoundCommands and UseCaseChecklistCommands on the real DbalRounds and
 * DbalChecklists, as pinround_app under row-level security (ADR-0044, FR-RND-17). Omar is SHOP-1's assignee and Mia its
 * tester. Besides Mia's and Omar's unshared drafts with pins, Lena's report and another organization's draft of Mia on
 * the same ticket id have pins that must never become items. Each share commits, so a write that slipped past the
 * rollback would stay; the rows are removed through the migrator. Every time is a fixed one in March 2027.
 */
final class UseCaseRoundCommandsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9a01';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f9a02';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f9a03';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f9a04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f9a05';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f9a11';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-0000000f9a1f';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f9a21';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f9a22';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-0000000f9a23';
    private const string OTHER_ORGANIZATION_DRAFT = '0197a3c0-0000-7000-8000-0000000f9a24';
    private const string EARLIER_CHECKLIST = '0197a3c0-0000-7000-8000-0000000f9a25';
    private const string OPEN_REVIEW = '0197a3c0-0000-7000-8000-0000000f9a26';
    private const string REPORT_PICTURE = '0197a3c0-0000-7000-8000-0000000f9a31';
    private const string MIAS_FIRST_PICTURE = '0197a3c0-0000-7000-8000-0000000f9a32';
    private const string MIAS_SECOND_PICTURE = '0197a3c0-0000-7000-8000-0000000f9a33';
    private const string OMARS_PICTURE = '0197a3c0-0000-7000-8000-0000000f9a34';
    private const string OTHER_ORGANIZATION_PICTURE = '0197a3c0-0000-7000-8000-0000000f9a35';
    private const string REPORT_PIN = '0197a3c0-0000-7000-8000-0000000f9a41';
    /** The pin of Mia's picture at position 0. */
    private const string MIAS_CUT_OFF_PIN = '0197a3c0-0000-7000-8000-0000000f9a42';
    /** The pin of Mia's picture at position 1, stored before the other one. */
    private const string MIAS_DOUBLE_WARNING_PIN = '0197a3c0-0000-7000-8000-0000000f9a43';
    private const string OMARS_PIN = '0197a3c0-0000-7000-8000-0000000f9a44';
    private const string OTHER_ORGANIZATION_PIN = '0197a3c0-0000-7000-8000-0000000f9a45';
    private const string EARLIER_ITEM = '0197a3c0-0000-7000-8000-0000000f9a51';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f9a61';
    private const string CREATED_AT = '2027-03-15 10:00:00+00';
    // An offset other than UTC, so a time written without its offset would read back an hour off.
    private const string SHARED_AT = '2027-03-16 09:30:00+01:00';
    private const string SHARED_AT_UTC = '2027-03-16 08:30:00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::REPORT, 'report', self::LENA, 0),
            self::picture(self::ACME_FOODS, self::REPORT_PICTURE, self::REPORT, 0),
            self::pin(self::ACME_FOODS, self::REPORT_PIN, self::REPORT_PICTURE, self::LENA),
            self::round(self::ACME_FOODS, self::MIAS_DRAFT, 'draft', self::MIA, null),
            self::picture(self::ACME_FOODS, self::MIAS_SECOND_PICTURE, self::MIAS_DRAFT, 1),
            self::pin(self::ACME_FOODS, self::MIAS_DOUBLE_WARNING_PIN, self::MIAS_SECOND_PICTURE, self::MIA),
            self::picture(self::ACME_FOODS, self::MIAS_FIRST_PICTURE, self::MIAS_DRAFT, 0),
            self::pin(self::ACME_FOODS, self::MIAS_CUT_OFF_PIN, self::MIAS_FIRST_PICTURE, self::MIA),
            self::round(self::ACME_FOODS, self::OMARS_DRAFT, 'draft', self::OMAR, null),
            self::picture(self::ACME_FOODS, self::OMARS_PICTURE, self::OMARS_DRAFT, 0),
            self::pin(self::ACME_FOODS, self::OMARS_PIN, self::OMARS_PICTURE, self::OMAR),
        ]);
        $this->roundRows()->addAllOf(self::OTHER_ORGANIZATION, [
            self::round(self::OTHER_ORGANIZATION, self::OTHER_ORGANIZATION_DRAFT, 'draft', self::MIA, null),
            self::picture(self::OTHER_ORGANIZATION, self::OTHER_ORGANIZATION_PICTURE, self::OTHER_ORGANIZATION_DRAFT, 0),
            self::pin(self::OTHER_ORGANIZATION, self::OTHER_ORGANIZATION_PIN, self::OTHER_ORGANIZATION_PICTURE, self::MIA),
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
    public function sharingTheTestersDraftMakesOneOpenItemPerPinOfItsOwnAndNoneOfAnyOtherRound(): void
    {
        $number = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->commands()->shareDraft(self::MIAS_DRAFT, new UserId(self::MIA), new \DateTimeImmutable(self::SHARED_AT)));

        self::assertSame(1, $number);
        self::assertSame(
            [
                [self::MIAS_DRAFT, self::MIAS_CUT_OFF_PIN, 'open', null, null, self::SHARED_AT_UTC],
                [self::MIAS_DRAFT, self::MIAS_DOUBLE_WARNING_PIN, 'open', null, null, self::SHARED_AT_UTC],
            ],
            $this->itemRowsOf(self::ACME_FOODS),
            'Exactly the two pins of the shared draft, and not the report\'s nor Omar\'s.',
        );
        self::assertSame([], $this->itemRowsOf(self::OTHER_ORGANIZATION));
    }

    #[Test]
    public function sharingTheAssigneesSelfCheckMakesNoItem(): void
    {
        $number = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->commands()->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), new \DateTimeImmutable(self::SHARED_AT)));

        self::assertSame(1, $number);
        self::assertSame([[1, self::SHARED_AT_UTC]], $this->roundRows()->runAs(self::ACME_FOODS, "SELECT number, to_char(shared_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM rvw_round WHERE id = ?", [self::OMARS_DRAFT]));
        self::assertSame([], $this->itemRowsOf(self::ACME_FOODS));
    }

    /**
     * A pin of Mia's draft is in a checklist already, so the second item the share writes is refused after the round was
     * saved as shared: the caller's transaction rolls back, and the draft is unshared again with no item of its own.
     */
    #[Test]
    public function anItemThatCannotBeWrittenRollsTheWholeShareBack(): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::EARLIER_CHECKLIST, 'checklist', self::LENA, null),
            ['rvw_check_item', [
                'organization_id' => self::ACME_FOODS, 'id' => self::EARLIER_ITEM, 'round_id' => self::EARLIER_CHECKLIST,
                'pin_id' => self::MIAS_DOUBLE_WARNING_PIN, 'state' => 'open', 'created_at' => self::CREATED_AT,
            ]],
        ]);
        $draftBefore = $this->draftRowOf(self::MIAS_DRAFT);
        self::assertSame([null, null, 1], $draftBefore, 'The draft is not unshared at version 1, so nothing below is proven.');

        $failure = null;
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->commands()->shareDraft(self::MIAS_DRAFT, new UserId(self::MIA), new \DateTimeImmutable(self::SHARED_AT)));
        } catch (\LogicException $refusal) {
            $failure = $refusal;
        }

        self::assertNotNull($failure, 'The share went through, so the rollback is not proven.');
        self::assertSame('A checklist item has an id added before, or names a pin that is not stored or is in a checklist already.', $failure->getMessage());
        self::assertSame($draftBefore, $this->draftRowOf(self::MIAS_DRAFT));
        self::assertSame(
            [[self::EARLIER_CHECKLIST, self::MIAS_DOUBLE_WARNING_PIN, 'open', null, null, '2027-03-15 10:00:00']],
            $this->itemRowsOf(self::ACME_FOODS),
            'Only the item seeded before; the share wrote none on its draft.',
        );
    }

    /**
     * The refusal is caught inside the transaction, which then commits, so a write the refusal slipped past would stay.
     */
    #[Test]
    public function aShareWhileAReviewRoundIsOpenIsRefusedAndLeavesTheDraftUnsharedAndUnnumbered(): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [self::round(self::ACME_FOODS, self::OPEN_REVIEW, 'review', self::LENA, 1)]);
        $draftBefore = $this->draftRowOf(self::OMARS_DRAFT);
        self::assertSame([null, null, 1], $draftBefore, 'The draft is not unshared at version 1, so nothing below is proven.');

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?RoundRefused {
            try {
                $this->commands()->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), new \DateTimeImmutable(self::SHARED_AT));
            } catch (RoundRefused $refused) {
                return $refused;
            }

            return null;
        });

        self::assertNotNull($refusal, 'The share went through while round 1 was open.');
        self::assertSame('Round 1 is still open. Wait for its verdict before you share.', $refusal->getMessage());
        self::assertSame([true, 1, null], [$refusal->isSharingBlocked(), $refusal->openRoundNumber(), $refusal->readyForUserId()]);
        self::assertSame($draftBefore, $this->draftRowOf(self::OMARS_DRAFT));
        self::assertSame([['open', 1]], $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT state, version FROM rvw_round WHERE id = ?', [self::OPEN_REVIEW]));
    }

    /**
     * Mia's findings are Round 1, ready for Omar, the assignee; his share closes it and becomes Round 2 in one commit.
     */
    #[Test]
    public function theAssigneesShareClosesTheOpenFindingsRoundAndTakesTheNextNumber(): void
    {
        $miasNumber = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->commands()->shareDraft(self::MIAS_DRAFT, new UserId(self::MIA), new \DateTimeImmutable(self::SHARED_AT)));
        self::assertSame(1, $miasNumber);

        $omarsNumber = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->commands()->shareDraft(self::OMARS_DRAFT, new UserId(self::OMAR), new \DateTimeImmutable(self::SHARED_AT)));

        self::assertSame(2, $omarsNumber);
        self::assertSame(
            [[self::REPORT, 0, 'open', 1], [self::MIAS_DRAFT, 1, 'closed', 3], [self::OMARS_DRAFT, 2, 'open', 2]],
            $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id, number, state, version FROM rvw_round ORDER BY number'),
            'Round 0 stays open; only the findings round closed.',
        );
        self::assertSame(
            [[self::OTHER_ORGANIZATION_DRAFT, null, 'open', 1]],
            $this->roundRows()->runAs(self::OTHER_ORGANIZATION, 'SELECT id, number, state, version FROM rvw_round'),
        );
    }

    /**
     * @return list<list<mixed>> round, pin, state, changed by, changed at and created at in UTC of each item, by pin id
     */
    private function itemRowsOf(string $organizationId): array
    {
        return $this->roundRows()->runAs(
            $organizationId,
            "SELECT round_id, pin_id, state, changed_by, changed_at, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM rvw_check_item ORDER BY pin_id",
        );
    }

    /**
     * @return list<mixed> number, shared at and version
     */
    private function draftRowOf(string $roundId): array
    {
        $rows = $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT number, shared_at, version FROM rvw_round WHERE id = ?', [$roundId]);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function commands(): UseCaseRoundCommands
    {
        $tenantContext = $this->tenantContext();
        $rounds = new DbalRounds($this->connection(), $tenantContext);
        $ticketTurnQueries = new InMemoryTicketTurnQueries([self::ticket()], new TicketId(self::BROKEN_TICKET));
        $checklistCommands = new UseCaseChecklistCommands(
            new DbalChecklists($this->connection(), $tenantContext),
            $rounds,
            new DbalChecklistQueries($this->connection(), $tenantContext),
            new RecordingReviewEvents(),
            new InMemoryPingClearing(),
            $tenantContext,
            $ticketTurnQueries,
        );

        return new UseCaseRoundCommands($rounds, $ticketTurnQueries, $tenantContext, new ReportPictures(new InMemoryUploadedMedia($tenantContext, [])), $checklistCommands);
    }

    private static function ticket(): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-0000000f9a71',
            '0197a3c0-0000-7000-8000-0000000f9a72',
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
    private static function round(string $organizationId, string $roundId, string $kind, string $ownerId, ?int $number): array
    {
        $isShared = null !== $number || 'checklist' === $kind;

        return ['rvw_round', [
            'organization_id' => $organizationId, 'id' => $roundId, 'ticket_id' => self::TICKET, 'number' => $number, 'kind' => $kind,
            'owner_id' => $ownerId, 'shared_at' => $isShared ? self::CREATED_AT : null, 'state' => 'open', 'version' => 1, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function picture(string $organizationId, string $screenshotId, string $roundId, int $position): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId, 'id' => $screenshotId, 'round_id' => $roundId, 'media_id' => self::MEDIA,
            'position' => $position, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $organizationId, string $pinId, string $screenshotId, string $authorId): array
    {
        return ['rvw_pin', [
            'organization_id' => $organizationId, 'id' => $pinId, 'screenshot_id' => $screenshotId, 'number' => 1, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => 'Looks off', 'source' => 'human', 'author_id' => $authorId, 'created_at' => self::CREATED_AT,
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
