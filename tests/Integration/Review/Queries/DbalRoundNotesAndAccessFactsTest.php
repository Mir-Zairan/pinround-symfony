<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\ScreenshotNotes;
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
 * DbalRoundQueries::accessFactsOf and roundNotes against the real database, on rows committed once per class, with the
 * services the container wires it with. Omar has an open unshared draft of the ticket with three pictures and four
 * pins, all inserted out of position and number order. Around it are the rows roundNotes must leave out: Lena's draft
 * of the same ticket, Omar's shared round and his deleted draft of it, his draft of another ticket, and a Beta Bakery
 * draft of the same ticket and owner ids. Every time is fixed in March 2027; nothing reads the clock.
 */
final class DbalRoundNotesAndAccessFactsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5601';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f5602';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f5611';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5612';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5621';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f5622';
    private const string TICKET_WITHOUT_DRAFT = '0197a3c0-0000-7000-8000-0000000f5623';
    private const string EMPTY_DRAFT_TICKET = '0197a3c0-0000-7000-8000-0000000f5624';
    private const string OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000f5631';
    private const string LENA_DRAFT = '0197a3c0-0000-7000-8000-0000000f5632';
    private const string OMAR_SHARED_ROUND = '0197a3c0-0000-7000-8000-0000000f5633';
    private const string OMAR_DELETED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5634';
    private const string OMAR_DRAFT_ON_OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f5635';
    private const string BAKERY_DRAFT = '0197a3c0-0000-7000-8000-0000000f5636';
    private const string OMAR_EMPTY_DRAFT = '0197a3c0-0000-7000-8000-0000000f5637';
    private const string UNKNOWN_ROUND = '0197a3c0-0000-7000-8000-0000000f56ff';
    private const string THIRD_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5641';
    private const string FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5642';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5643';
    private const string LENA_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5644';
    private const string SHARED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5645';
    private const string DELETED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5646';
    private const string OTHER_TICKET_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5647';
    private const string BAKERY_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5648';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f5651';

    private const string BEFORE_ANY_ROW = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';
    private const string DELETED_AT = '2027-03-16 11:00:00+00';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::addRows(self::ACME_FOODS, [
            [self::OMAR_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open', null],
            [self::LENA_DRAFT, self::TICKET, null, 'draft', self::LENA, null, 'open', null],
            [self::OMAR_SHARED_ROUND, self::TICKET, 1, 'draft', self::OMAR, self::SHARED_AT, 'open', null],
            [self::OMAR_DELETED_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'closed', self::DELETED_AT],
            [self::OMAR_DRAFT_ON_OTHER_TICKET, self::OTHER_TICKET, null, 'draft', self::OMAR, null, 'open', null],
            [self::OMAR_EMPTY_DRAFT, self::EMPTY_DRAFT_TICKET, null, 'draft', self::OMAR, null, 'open', null],
        ], [
            [self::THIRD_SCREENSHOT, self::OMAR_DRAFT, 2, 'looks_right', 'Cart', 'All fine.'],
            [self::FIRST_SCREENSHOT, self::OMAR_DRAFT, 0, 'not_right', 'Checkout', 'The total is off.'],
            [self::SECOND_SCREENSHOT, self::OMAR_DRAFT, 1, null, null, null],
            [self::LENA_SCREENSHOT, self::LENA_DRAFT, 0, null, 'Lena only', null],
            [self::SHARED_SCREENSHOT, self::OMAR_SHARED_ROUND, 0, null, 'Shared', null],
            [self::DELETED_SCREENSHOT, self::OMAR_DELETED_DRAFT, 0, null, 'Deleted', null],
            [self::OTHER_TICKET_SCREENSHOT, self::OMAR_DRAFT_ON_OTHER_TICKET, 0, null, 'Other ticket', null],
        ], [
            ['0197a3c0-0000-7000-8000-0000000f5661', self::FIRST_SCREENSHOT, 3, 'Prix affiché en €'],
            ['0197a3c0-0000-7000-8000-0000000f5662', self::FIRST_SCREENSHOT, 1, 'Button hidden'],
            ['0197a3c0-0000-7000-8000-0000000f5663', self::FIRST_SCREENSHOT, 2, 'Wrong colour'],
            ['0197a3c0-0000-7000-8000-0000000f5664', self::THIRD_SCREENSHOT, 1, 'Fine here'],
            ['0197a3c0-0000-7000-8000-0000000f5665', self::LENA_SCREENSHOT, 1, 'Lena pin'],
            ['0197a3c0-0000-7000-8000-0000000f5666', self::SHARED_SCREENSHOT, 1, 'Shared pin'],
            ['0197a3c0-0000-7000-8000-0000000f5667', self::DELETED_SCREENSHOT, 1, 'Deleted pin'],
            ['0197a3c0-0000-7000-8000-0000000f5668', self::OTHER_TICKET_SCREENSHOT, 1, 'Other ticket pin'],
        ]);
        // rvw_round.ticket_id and owner_id have no foreign key, so Beta Bakery may hold a draft with Acme's ids.
        self::addRows(self::BETA_BAKERY, [
            [self::BAKERY_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open', null],
        ], [
            [self::BAKERY_SCREENSHOT, self::BAKERY_DRAFT, 0, null, 'Bakery', null],
        ], [
            ['0197a3c0-0000-7000-8000-0000000f5669', self::BAKERY_SCREENSHOT, 1, 'Bakery pin'],
        ]);
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
    public function anUnsharedDraftIsOneOfItsOwnersAndNotDeleted(): void
    {
        $facts = $this->accessFactsAs(self::ACME_FOODS, self::OMAR_DRAFT);

        self::assertEquals(new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::OMAR_DRAFT, new TicketId(self::TICKET), new UserId(self::OMAR), true, false), $facts);
    }

    #[Test]
    public function aSharedRoundIsNotAnUnsharedDraft(): void
    {
        $facts = $this->accessFactsAs(self::ACME_FOODS, self::OMAR_SHARED_ROUND);

        self::assertNotNull($facts);
        self::assertFalse($facts->isUnsharedDraft);
        self::assertFalse($facts->isDeleted);
        self::assertSame(self::OMAR, $facts->ownerId->toString());
    }

    #[Test]
    public function aDeletedDraftIsFlaggedDeleted(): void
    {
        $facts = $this->accessFactsAs(self::ACME_FOODS, self::OMAR_DELETED_DRAFT);

        self::assertNotNull($facts);
        self::assertTrue($facts->isDeleted);
        self::assertTrue($facts->isUnsharedDraft);
    }

    #[Test]
    public function anotherPersonsDraftCarriesItsOwnerSoTheVoterCanRefuseTheCaller(): void
    {
        $facts = $this->accessFactsAs(self::ACME_FOODS, self::LENA_DRAFT);

        self::assertNotNull($facts);
        self::assertSame(self::LENA, $facts->ownerId->toString());
        self::assertSame(self::TICKET, $facts->ticketId->toString());
    }

    #[Test]
    public function anUnknownRoundIdHasNoFacts(): void
    {
        self::assertNull($this->accessFactsAs(self::ACME_FOODS, self::UNKNOWN_ROUND));
    }

    #[Test]
    public function aMalformedRoundIdHasNoFactsAndCostsNoQuery(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            self::assertNull($this->roundQueries()->accessFactsOf('not-a-uuid'));

            return self::queryCountOf($this->debugData());
        });

        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function anotherOrganizationsRoundIsHiddenFromTheTenantAndSeenByItsOwn(): void
    {
        self::assertNull($this->accessFactsAs(self::ACME_FOODS, self::BAKERY_DRAFT));

        $facts = $this->accessFactsAs(self::BETA_BAKERY, self::BAKERY_DRAFT);
        self::assertNotNull($facts);
        self::assertTrue($facts->organizationId->equals(new OrganizationId(self::BETA_BAKERY)));
    }

    #[Test]
    public function accessFactsCostOneQuery(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            self::assertNotNull($this->roundQueries()->accessFactsOf(self::OMAR_DRAFT));

            return self::queryCountOf($this->debugData());
        });

        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function roundNotesAreTheOwnersOpenUnsharedDraftWithPicturesByPositionAndPinNotesByNumber(): void
    {
        $notes = $this->notesAs(self::ACME_FOODS, self::TICKET, self::OMAR);

        self::assertNotNull($notes);
        self::assertSame(self::OMAR_DRAFT, $notes->roundId);
        self::assertNotSame([], $notes->screenshots);
        self::assertEquals([
            new ScreenshotNotes(self::FIRST_SCREENSHOT, 0, 'Checkout', 'The total is off.', 'not_right', [1 => 'Button hidden', 2 => 'Wrong colour', 3 => 'Prix affiché en €']),
            new ScreenshotNotes(self::SECOND_SCREENSHOT, 1, null, null, null, []),
            new ScreenshotNotes(self::THIRD_SCREENSHOT, 2, 'Cart', 'All fine.', 'looks_right', [1 => 'Fine here']),
        ], $notes->screenshots);
        self::assertSame([1, 2, 3], array_keys($notes->screenshots[0]->pinNotes));
    }

    #[Test]
    public function roundNotesLeaveOutEveryOtherRoundOfTheTicketAndOtherOrganizations(): void
    {
        $notes = $this->notesAs(self::ACME_FOODS, self::TICKET, self::OMAR);

        self::assertNotNull($notes);
        $text = json_encode($notes, \JSON_THROW_ON_ERROR);
        foreach (['Lena only', 'Lena pin', 'Shared', 'Deleted', 'Other ticket', 'Bakery'] as $foreign) {
            self::assertStringNotContainsString($foreign, $text, \sprintf('The notes hold "%s", which is not the owner\'s open unshared draft.', $foreign));
        }
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT, self::THIRD_SCREENSHOT], array_map(static fn (ScreenshotNotes $screenshot): string => $screenshot->screenshotId, $notes->screenshots));
    }

    #[Test]
    public function aSecondOwnersNotesOfTheSameTicketAreTheirsAlone(): void
    {
        $notes = $this->notesAs(self::ACME_FOODS, self::TICKET, self::LENA);

        self::assertNotNull($notes);
        self::assertSame(self::LENA_DRAFT, $notes->roundId);
        self::assertEquals([new ScreenshotNotes(self::LENA_SCREENSHOT, 0, 'Lena only', null, null, [1 => 'Lena pin'])], $notes->screenshots);
    }

    #[Test]
    public function aDraftOfAnotherTicketIsNotThisTicketsNotes(): void
    {
        $notes = $this->notesAs(self::ACME_FOODS, self::OTHER_TICKET, self::OMAR);

        self::assertNotNull($notes);
        self::assertSame(self::OMAR_DRAFT_ON_OTHER_TICKET, $notes->roundId);
        self::assertSame([self::OTHER_TICKET_SCREENSHOT], array_map(static fn (ScreenshotNotes $screenshot): string => $screenshot->screenshotId, $notes->screenshots));
    }

    #[Test]
    public function aDraftWithNoPictureHasNotesWithNoScreenshot(): void
    {
        $notes = $this->notesAs(self::ACME_FOODS, self::EMPTY_DRAFT_TICKET, self::OMAR);

        self::assertEquals(new RoundNotes(self::OMAR_EMPTY_DRAFT, []), $notes);
    }

    #[Test]
    public function aTicketWhereTheOwnerHasNoOpenUnsharedDraftHasNoNotes(): void
    {
        self::assertNull($this->notesAs(self::ACME_FOODS, self::TICKET_WITHOUT_DRAFT, self::OMAR));
        self::assertNull($this->notesAs(self::ACME_FOODS, self::OTHER_TICKET, self::LENA));
    }

    #[Test]
    public function anotherOrganizationsDraftOfTheSameTicketAndOwnerIdsIsNotLeakedAndIsItsOwnToItsTenant(): void
    {
        $notesOfBakery = $this->notesAs(self::BETA_BAKERY, self::TICKET, self::OMAR);

        self::assertNotNull($notesOfBakery);
        self::assertSame(self::BAKERY_DRAFT, $notesOfBakery->roundId);
        self::assertSame([self::BAKERY_SCREENSHOT], array_map(static fn (ScreenshotNotes $screenshot): string => $screenshot->screenshotId, $notesOfBakery->screenshots));
        self::assertSame(self::OMAR_DRAFT, $this->notesAs(self::ACME_FOODS, self::TICKET, self::OMAR)?->roundId);
    }

    #[Test]
    public function roundNotesCostOneQueryWithSeveralPicturesAndPins(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            $notes = $this->roundQueries()->roundNotes(new TicketId(self::TICKET), new UserId(self::OMAR));
            $queryCount = self::queryCountOf($this->debugData());
            self::assertNotNull($notes);
            self::assertCount(3, $notes->screenshots);

            return $queryCount;
        });

        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function aReadOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->roundQueries()->roundNotes(new TicketId(self::TICKET), new UserId(self::OMAR));
            self::fail('Notes were read outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    #[Test]
    public function accessFactsReadOutsideATransactionAreRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->roundQueries()->accessFactsOf(self::OMAR_DRAFT);
            self::fail('Access facts were read outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    /**
     * The round, screenshot and pin reads of accessFactsOf and roundNotes with their organization condition taken out,
     * run as pinround_app: under Acme Foods as tenant, row-level security alone hides Beta Bakery's draft, picture and
     * pin, which Beta's own tenant gets.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsDraftPictureAndPin(): void
    {
        $rowIdsAs = static fn (string $organizationId): array => self::transactions()->rolledBackAs($organizationId, static fn (): array => [
            self::connection()->fetchOne('SELECT current_user'),
            self::connection()->fetchFirstColumn(
                'SELECT round.id FROM rvw_round AS round WHERE round.id = CAST(:round AS uuid)',
                ['round' => self::BAKERY_DRAFT],
            ),
            self::connection()->fetchFirstColumn(
                'SELECT round.id FROM rvw_round AS round WHERE round.ticket_id = CAST(:ticket AS uuid) AND round.owner_id = CAST(:owner AS uuid)'
                ." AND round.kind = 'draft' AND round.shared_at IS NULL AND round.state = 'open' AND round.deleted_at IS NULL AND round.id = CAST(:round AS uuid)",
                ['ticket' => self::TICKET, 'owner' => self::OMAR, 'round' => self::BAKERY_DRAFT],
            ),
            self::connection()->fetchFirstColumn(
                'SELECT screenshot.id FROM rvw_screenshot AS screenshot WHERE screenshot.round_id = CAST(:round AS uuid)',
                ['round' => self::BAKERY_DRAFT],
            ),
            self::connection()->fetchFirstColumn(
                'SELECT pin.id FROM rvw_pin AS pin WHERE pin.screenshot_id = CAST(:screenshot AS uuid)',
                ['screenshot' => self::BAKERY_SCREENSHOT],
            ),
        ]);

        self::assertSame(['pinround_app', [], [], [], []], $rowIdsAs(self::ACME_FOODS));
        self::assertSame(
            ['pinround_app', [self::BAKERY_DRAFT], [self::BAKERY_DRAFT], [self::BAKERY_SCREENSHOT], ['0197a3c0-0000-7000-8000-0000000f5669']],
            $rowIdsAs(self::BETA_BAKERY),
        );
    }

    #[Test]
    public function anOwnersDraftComesWithAllItsScreenshotsByPositionAndItsPinsByNumberWhateverTheIdsCase(): void
    {
        $draft = $this->draftAs(self::ACME_FOODS, strtoupper(self::OMAR_DRAFT), self::OMAR);

        self::assertNotNull($draft);
        self::assertSame(self::OMAR_DRAFT, $draft->id);
        self::assertNull($draft->sharedAt);
        self::assertSame([self::FIRST_SCREENSHOT, self::SECOND_SCREENSHOT, self::THIRD_SCREENSHOT], array_map(static fn (RoundScreenshotView $screenshot): string => $screenshot->id, $draft->screenshots));
        self::assertSame(
            [[1, 'Button hidden'], [2, 'Wrong colour'], [3, 'Prix affiché en €']],
            array_map(static fn (RoundPinView $pin): array => [$pin->number, $pin->note], $draft->screenshots[0]->pins),
        );
        self::assertSame([], $draft->screenshots[1]->pins);
    }

    #[Test]
    public function anOwnersDraftSharedSinceIsStillTheirsToRead(): void
    {
        $round = $this->draftAs(self::ACME_FOODS, self::OMAR_SHARED_ROUND, self::OMAR);

        self::assertNotNull($round);
        self::assertNotNull($round->sharedAt);
        self::assertSame([self::SHARED_SCREENSHOT], array_map(static fn (RoundScreenshotView $screenshot): string => $screenshot->id, $round->screenshots));
    }

    #[Test]
    public function aDraftWithNoScreenshotComesWithNone(): void
    {
        $draft = $this->draftAs(self::ACME_FOODS, self::OMAR_EMPTY_DRAFT, self::OMAR);

        self::assertNotNull($draft);
        self::assertSame([], $draft->screenshots);
    }

    #[Test]
    public function aDraftOfAnotherPersonADeletedOneAnotherOrganizationsAndAnUnknownOrMalformedIdAreNull(): void
    {
        $cases = [
            'the draft of Lena asked by Omar' => [self::ACME_FOODS, self::LENA_DRAFT, self::OMAR],
            'the draft of Omar asked by Lena' => [self::ACME_FOODS, self::OMAR_DRAFT, self::LENA],
            'a deleted draft of Omar' => [self::ACME_FOODS, self::OMAR_DELETED_DRAFT, self::OMAR],
            'a Beta Bakery draft asked under Acme Foods' => [self::ACME_FOODS, self::BAKERY_DRAFT, self::OMAR],
            'an Acme Foods draft asked under Beta Bakery' => [self::BETA_BAKERY, self::OMAR_DRAFT, self::OMAR],
            'an unknown id' => [self::ACME_FOODS, self::UNKNOWN_ROUND, self::OMAR],
            'a malformed id' => [self::ACME_FOODS, 'not-a-uuid', self::OMAR],
        ];

        foreach ($cases as $name => [$organizationId, $roundId, $owner]) {
            self::assertNull($this->draftAs($organizationId, $roundId, $owner), $name);
        }
    }

    #[Test]
    public function readingADraftOutsideATransactionIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->roundQueries()->draftOf(self::OMAR_DRAFT, new UserId(self::OMAR));
    }

    private function draftAs(string $organizationId, string $roundId, string $owner): ?RoundView
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundView => $this->roundQueries()->draftOf($roundId, new UserId($owner)));
    }

    private function accessFactsAs(string $organizationId, string $roundId): ?RoundAccessFacts
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundAccessFacts => $this->roundQueries()->accessFactsOf($roundId));
    }

    private function notesAs(string $organizationId, string $ticketId, string $owner): ?RoundNotes
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundNotes => $this->roundQueries()->roundNotes(new TicketId($ticketId), new UserId($owner)));
    }

    /**
     * @param list<array{string, string, ?int, string, string, ?string, string, ?string}> $rounds      id, ticket, number, kind, owner, shared at, state, deleted at
     * @param list<array{string, string, int, ?string, ?string, ?string}>                 $screenshots id, round, position, verdict, title, summary
     * @param list<array{string, string, int, string}>                                    $pins        id, screenshot, number, note
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
            foreach ($screenshots as [$id, $roundId, $position, $verdict, $title, $summary]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, variant_label, verdict, title, summary, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, '".self::BEFORE_ANY_ROW."')",
                    [$organizationId, $id, $roundId, self::MEDIA, $position, $verdict, $title, $summary],
                );
            }
            foreach ($pins as [$id, $screenshotId, $number, $note]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, verdict, source, ai_state, author_id, created_at) VALUES (?, ?, ?, ?, '10.00', '20.00', ?, NULL, 'human', NULL, ?, '".self::BEFORE_ANY_ROW."')",
                    [$organizationId, $id, $screenshotId, $number, $note, self::OMAR],
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
