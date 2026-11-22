<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\PinNote;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundQueries;
use App\Review\Api\ScreenshotNotes;
use App\Review\Api\SharedRoundNotes;
use App\Review\Infrastructure\Doctrine\DbalRoundQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRoundQueries::sharedRoundNotes, and the pin rule it shares with roundNotes, against the real database on rows
 * committed once per class. The ticket has its report (Round 0), Lena's shared draft (Round 1) and Omar's (Round 2), the
 * newest; above them a checklist (Round 3) and a review round (Round 4), neither of
 * which holds the pictures asked for; and Omar's unshared draft, which has no number. Omar's Round 2 and his unshared
 * draft each hold a person's pin, a suggested, a kept and a dismissed AI pin, inserted out of number order. Beta Bakery
 * holds a Round 9 of the same ticket id. Every time is fixed in March 2027; nothing reads the clock.
 */
final class DbalSharedRoundNotesAndPinRuleTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5901';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f5902';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f5911';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5912';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f5913';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5921';
    private const string REPORT_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000f5922';
    private const string UNSHARED_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000f5923';
    private const string TICKET_WITHOUT_ROUNDS = '0197a3c0-0000-7000-8000-0000000f5924';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f5931';
    private const string LENAS_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5932';
    private const string OMARS_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5933';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-0000000f5934';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f5935';
    private const string OMARS_UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5937';
    private const string LONE_REPORT = '0197a3c0-0000-7000-8000-0000000f5938';
    private const string LONE_UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5939';
    private const string BAKERY_ROUND = '0197a3c0-0000-7000-8000-0000000f593a';
    private const string CHECKOUT_SHOT = '0197a3c0-0000-7000-8000-0000000f5941';
    private const string SUGGESTIONS_ONLY_SHOT = '0197a3c0-0000-7000-8000-0000000f5942';
    private const string UNSHARED_SHOT = '0197a3c0-0000-7000-8000-0000000f5943';
    private const string LONE_REPORT_SHOT = '0197a3c0-0000-7000-8000-0000000f5944';
    private const string CHECKOUT_MEDIA = '0197a3c0-0000-7000-8000-0000000f5951';
    private const string SUGGESTIONS_ONLY_MEDIA = '0197a3c0-0000-7000-8000-0000000f5952';
    private const string UNSHARED_MEDIA = '0197a3c0-0000-7000-8000-0000000f5953';
    private const string LONE_REPORT_MEDIA = '0197a3c0-0000-7000-8000-0000000f5954';
    private const string OTHER_MEDIA = '0197a3c0-0000-7000-8000-0000000f5955';

    private const string BEFORE_ANY_ROW = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::addRows(self::ACME_FOODS, [
            // Inserted newest first, so neither insertion order nor id order is the number order.
            [self::REVIEW, self::TICKET, 4, 'review', self::MIA, self::SHARED_AT, 'open', null],
            [self::CHECKLIST, self::TICKET, null, 'checklist', self::MIA, self::SHARED_AT, 'open', null],
            [self::OMARS_SHARED_DRAFT, self::TICKET, 2, 'draft', self::OMAR, self::SHARED_AT, 'open', null],
            [self::LENAS_SHARED_DRAFT, self::TICKET, 1, 'draft', self::LENA, self::SHARED_AT, 'open', null],
            [self::REPORT, self::TICKET, 0, 'report', self::MIA, self::SHARED_AT, 'open', null],
            [self::OMARS_UNSHARED_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open', null],
            [self::LONE_REPORT, self::REPORT_ONLY_TICKET, 0, 'report', self::MIA, self::SHARED_AT, 'open', null],
            [self::LONE_UNSHARED_DRAFT, self::UNSHARED_ONLY_TICKET, null, 'draft', self::OMAR, null, 'open', null],
        ], [
            ['0197a3c0-0000-7000-8000-0000000f5946', self::LENAS_SHARED_DRAFT, 0, null, 'Older', null, self::OTHER_MEDIA],
            ['0197a3c0-0000-7000-8000-0000000f5947', self::REPORT, 0, null, 'Report', null, self::OTHER_MEDIA],
            [self::SUGGESTIONS_ONLY_SHOT, self::OMARS_SHARED_DRAFT, 1, 'looks_right', null, null, self::SUGGESTIONS_ONLY_MEDIA],
            [self::CHECKOUT_SHOT, self::OMARS_SHARED_DRAFT, 0, 'not_right', 'Checkout', 'The total is off.', self::CHECKOUT_MEDIA],
            [self::UNSHARED_SHOT, self::OMARS_UNSHARED_DRAFT, 0, null, 'Unshared', null, self::UNSHARED_MEDIA],
            [self::LONE_REPORT_SHOT, self::LONE_REPORT, 0, null, 'As reported', null, self::LONE_REPORT_MEDIA],
            ['0197a3c0-0000-7000-8000-0000000f5948', self::LONE_UNSHARED_DRAFT, 0, null, 'Lone unshared', null, self::OTHER_MEDIA],
        ], [
            ['0197a3c0-0000-7000-8000-0000000f5961', self::CHECKOUT_SHOT, 4, 'Person\'s pin', 'human', null],
            ['0197a3c0-0000-7000-8000-0000000f5962', self::CHECKOUT_SHOT, 1, 'AI suggestion', 'ai', 'suggested'],
            ['0197a3c0-0000-7000-8000-0000000f5963', self::CHECKOUT_SHOT, 3, 'Kept AI pin', 'ai', 'kept'],
            ['0197a3c0-0000-7000-8000-0000000f5964', self::CHECKOUT_SHOT, 2, 'Dismissed AI pin', 'ai', 'dismissed'],
            ['0197a3c0-0000-7000-8000-0000000f5965', self::SUGGESTIONS_ONLY_SHOT, 1, 'Only a suggestion', 'ai', 'suggested'],
            ['0197a3c0-0000-7000-8000-0000000f5966', self::UNSHARED_SHOT, 4, 'Draft dismissed AI pin', 'ai', 'dismissed'],
            ['0197a3c0-0000-7000-8000-0000000f5967', self::UNSHARED_SHOT, 3, 'Draft kept AI pin', 'ai', 'kept'],
            ['0197a3c0-0000-7000-8000-0000000f5968', self::UNSHARED_SHOT, 2, 'Draft AI suggestion', 'ai', 'suggested'],
            ['0197a3c0-0000-7000-8000-0000000f5969', self::UNSHARED_SHOT, 1, 'Draft person\'s pin', 'human', null],
            ['0197a3c0-0000-7000-8000-0000000f596a', self::LONE_REPORT_SHOT, 1, 'Reported pin', 'human', null],
        ]);
        // rvw_round.ticket_id has no foreign key, so Beta Bakery may hold a round with Acme's ticket id.
        self::addRows(self::BETA_BAKERY, [
            [self::BAKERY_ROUND, self::TICKET, 9, 'draft', self::OMAR, self::SHARED_AT, 'open', null],
        ], [
            ['0197a3c0-0000-7000-8000-0000000f5949', self::BAKERY_ROUND, 0, null, 'Bakery', null, self::OTHER_MEDIA],
        ], []);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function theNewestSharedDraftRoundComesWithItsPicturesByPositionTheirMediaAndOnlyThePinsThatCount(): void
    {
        $notes = $this->sharedNotesAs(self::ACME_FOODS, self::TICKET);

        self::assertEquals(new SharedRoundNotes(self::OMARS_SHARED_DRAFT, 2, SharedRoundNotes::DRAFT, [
            new ScreenshotNotes(self::CHECKOUT_SHOT, 0, 'Checkout', 'The total is off.', 'not_right', [
                new PinNote(3, 30.25, 21.5, 'Kept AI pin'),
                new PinNote(4, 40.25, 28.5, 'Person\'s pin'),
            ], self::CHECKOUT_MEDIA),
            new ScreenshotNotes(self::SUGGESTIONS_ONLY_SHOT, 1, null, null, 'looks_right', [], self::SUGGESTIONS_ONLY_MEDIA),
        ]), $notes);
    }

    #[Test]
    public function noOtherRoundOfTheTicketAndNoSuggestedOrDismissedAiPinIsInTheSharedNotes(): void
    {
        $notes = $this->sharedNotesAs(self::ACME_FOODS, self::TICKET);

        self::assertNotNull($notes);
        $text = json_encode($notes, \JSON_THROW_ON_ERROR);
        foreach (['AI suggestion', 'Dismissed AI pin', 'Only a suggestion', 'Older', 'Report', 'Unshared', 'Bakery'] as $foreign) {
            self::assertStringNotContainsString($foreign, $text, \sprintf('The shared notes hold "%s".', $foreign));
        }
    }

    #[Test]
    public function aTicketWithOnlyItsReportAnswersTheReportAsRoundZero(): void
    {
        $notes = $this->sharedNotesAs(self::ACME_FOODS, self::REPORT_ONLY_TICKET);

        self::assertEquals(new SharedRoundNotes(self::LONE_REPORT, 0, SharedRoundNotes::REPORT, [
            new ScreenshotNotes(self::LONE_REPORT_SHOT, 0, 'As reported', null, null, [new PinNote(1, 10.25, 7.5, 'Reported pin')], self::LONE_REPORT_MEDIA),
        ]), $notes);
    }

    #[Test]
    public function aTicketWithNoSharedReportOrDraftRoundHasNoSharedNotes(): void
    {
        self::assertNull($this->sharedNotesAs(self::ACME_FOODS, self::UNSHARED_ONLY_TICKET), 'An unshared draft, which has no number, is never the shared round.');
        self::assertNull($this->sharedNotesAs(self::ACME_FOODS, self::TICKET_WITHOUT_ROUNDS));
    }

    #[Test]
    public function anotherOrganizationsNewerRoundOfTheSameTicketIdIsNotLeakedAndIsItsOwnToItsTenant(): void
    {
        self::assertSame(self::OMARS_SHARED_DRAFT, $this->sharedNotesAs(self::ACME_FOODS, self::TICKET)?->roundId);

        $bakeryNotes = $this->sharedNotesAs(self::BETA_BAKERY, self::TICKET);
        self::assertNotNull($bakeryNotes);
        self::assertSame([self::BAKERY_ROUND, 9], [$bakeryNotes->roundId, $bakeryNotes->number]);
    }

    #[Test]
    public function sharedNotesCostOneQueryWithSeveralPicturesAndPins(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            $notes = $this->roundQueries()->sharedRoundNotes(new TicketId(self::TICKET));
            $queryCount = self::queryCountOf($this->debugData());
            self::assertNotNull($notes);
            self::assertCount(2, $notes->screenshots);

            return $queryCount;
        });

        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function sharedNotesReadOutsideATransactionAreRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->roundQueries()->sharedRoundNotes(new TicketId(self::TICKET));
            self::fail('Shared notes were read outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    #[Test]
    public function theOwnersDraftNotesKeepAPersonsPinAndAKeptAiPinAndLeaveOutTheSuggestedAndDismissedOnes(): void
    {
        $notes = $this->draftNotesAs(self::ACME_FOODS, self::TICKET, self::OMAR);

        self::assertEquals(new RoundNotes(self::OMARS_UNSHARED_DRAFT, [
            new ScreenshotNotes(self::UNSHARED_SHOT, 0, 'Unshared', null, null, [
                new PinNote(1, 10.25, 7.5, 'Draft person\'s pin'),
                new PinNote(3, 30.25, 21.5, 'Draft kept AI pin'),
            ], self::UNSHARED_MEDIA),
        ]), $notes);
    }

    private function sharedNotesAs(string $organizationId, string $ticketId): ?SharedRoundNotes
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?SharedRoundNotes => $this->roundQueries()->sharedRoundNotes(new TicketId($ticketId)));
    }

    private function draftNotesAs(string $organizationId, string $ticketId, string $owner): ?RoundNotes
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundNotes => $this->roundQueries()->roundNotes(new TicketId($ticketId), new UserId($owner)));
    }

    /**
     * @param list<array{string, string, ?int, string, string, ?string, string, ?string}> $rounds      id, ticket, number, kind, owner, shared at, state, deleted at
     * @param list<array{string, string, int, ?string, ?string, ?string, string}>         $screenshots id, round, position, verdict, title, summary, media
     * @param list<array{string, string, int, string, string, ?string}>                   $pins        id, screenshot, number, note, source, AI state
     */
    private static function addRows(string $organizationId, array $rounds, array $screenshots, array $pins): void
    {
        self::transactions()->committedAs($organizationId, static function () use ($organizationId, $rounds, $screenshots, $pins): void {
            foreach ($rounds as $round) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '".self::BEFORE_ANY_ROW."', ?)",
                    [$organizationId, ...$round],
                );
            }
            foreach ($screenshots as [$id, $roundId, $position, $verdict, $title, $summary, $mediaId]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, variant_label, verdict, title, summary, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, '".self::BEFORE_ANY_ROW."')",
                    [$organizationId, $id, $roundId, $mediaId, $position, $verdict, $title, $summary],
                );
            }
            foreach ($pins as [$id, $screenshotId, $number, $note, $source, $aiState]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, verdict, source, ai_state, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, '".self::BEFORE_ANY_ROW."')",
                    [$organizationId, $id, $screenshotId, $number, \sprintf('%.2f', $number * 10 + 0.25), \sprintf('%.2f', $number * 7 + 0.5), $note, $source, $aiState, self::OMAR],
                );
            }
        });
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function roundQueries(): RoundQueries
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof DbalRoundQueries);

        return $roundQueries;
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::entityManager()->getConnection();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
