<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistView;
use App\Review\Application\Checklist\CheckItemPicture;
use App\Review\Application\Checklist\RoundChecklist;
use App\Review\Application\Checklist\RoundChecklistQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ChecklistQueries and RoundChecklistQueries as the container wires them, under row-level security. Lena reported
 * SHOP-1 of Acme Foods with three pins on two pictures, given out of order, and SHOP-2 with one; Beta Bakery reported a
 * ticket with SHOP-1's id. Every checklist has one item confirmed OK, so a count that took in another ticket's or
 * organization's items would be off. SHOP-1 also has Mia's findings round with two items and Lena's review round with
 * one, which no checklist may show.
 */
final class DbalChecklistQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8d01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8d02';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f8d04';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f8d05';
    private const string TICKET_WITHOUT_CHECKLIST = '0197a3c0-0000-7000-8000-0000000f8d06';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8d07';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f8d08';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8d09';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f8d11';
    private const string OTHER_REPORT = '0197a3c0-0000-7000-8000-0000000f8d12';
    private const string BETA_REPORT = '0197a3c0-0000-7000-8000-0000000f8d13';
    private const string FIRST_PICTURE = '0197a3c0-0000-7000-8000-0000000f8d21';
    private const string SECOND_PICTURE = '0197a3c0-0000-7000-8000-0000000f8d22';
    private const string OTHER_PICTURE = '0197a3c0-0000-7000-8000-0000000f8d23';
    private const string BETA_PICTURE = '0197a3c0-0000-7000-8000-0000000f8d24';
    private const string WRONG_TOTAL_PIN = '0197a3c0-0000-7000-8000-0000000f8d31';
    private const string MISSING_PRICE_PIN = '0197a3c0-0000-7000-8000-0000000f8d32';
    private const string SMALL_LOGO_PIN = '0197a3c0-0000-7000-8000-0000000f8d33';
    private const string OTHER_TICKET_PIN = '0197a3c0-0000-7000-8000-0000000f8d34';
    private const string BETA_PIN = '0197a3c0-0000-7000-8000-0000000f8d35';
    private const string FINDINGS = '0197a3c0-0000-7000-8000-0000000f8d15';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f8d16';
    private const string FINDINGS_PICTURE = '0197a3c0-0000-7000-8000-0000000f8d25';
    private const string BADGE_PIN = '0197a3c0-0000-7000-8000-0000000f8d36';
    private const string CUT_TEXT_PIN = '0197a3c0-0000-7000-8000-0000000f8d37';
    private const string BADGE_ITEM = '0197a3c0-0000-7000-8000-0000000f8d41';
    private const string CUT_TEXT_ITEM = '0197a3c0-0000-7000-8000-0000000f8d42';
    private const string REVIEW_PICTURE = '0197a3c0-0000-7000-8000-0000000f8d26';
    private const string REVIEW_PIN = '0197a3c0-0000-7000-8000-0000000f8d38';
    private const string REVIEW_ITEM = '0197a3c0-0000-7000-8000-0000000f8d43';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f8d51';
    private const string CREATED_AT = '2027-03-16 08:30:00+00:00';
    private const string NOW = '2027-03-16 09:30:00+00:00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        // The second picture comes first and pin 2 before pin 1, so the order forTicket gives comes from the positions
        // and numbers.
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::report(self::ACME_FOODS, self::REPORT, self::TICKET),
            self::report(self::ACME_FOODS, self::OTHER_REPORT, self::OTHER_TICKET),
            self::report(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000f8d14', self::TICKET_WITHOUT_CHECKLIST),
            self::picture(self::ACME_FOODS, self::SECOND_PICTURE, self::REPORT, 1),
            self::picture(self::ACME_FOODS, self::FIRST_PICTURE, self::REPORT, 0),
            self::picture(self::ACME_FOODS, self::OTHER_PICTURE, self::OTHER_REPORT, 0),
            self::pin(self::ACME_FOODS, self::SMALL_LOGO_PIN, self::SECOND_PICTURE, 1, 'Logo too small.'),
            self::pin(self::ACME_FOODS, self::WRONG_TOTAL_PIN, self::FIRST_PICTURE, 2, 'Wrong total.'),
            self::pin(self::ACME_FOODS, self::MISSING_PRICE_PIN, self::FIRST_PICTURE, 1, ''),
            self::pin(self::ACME_FOODS, self::OTHER_TICKET_PIN, self::OTHER_PICTURE, 1, 'Button hidden.'),
        ]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::report(self::BETA_BAKERY, self::BETA_REPORT, self::TICKET),
            self::picture(self::BETA_BAKERY, self::BETA_PICTURE, self::BETA_REPORT, 0),
            self::pin(self::BETA_BAKERY, self::BETA_PIN, self::BETA_PICTURE, 3, 'Wrong colour.'),
        ]);
        foreach ([[self::ACME_FOODS, self::TICKET, self::MISSING_PRICE_PIN], [self::ACME_FOODS, self::OTHER_TICKET, self::OTHER_TICKET_PIN], [self::BETA_BAKERY, self::TICKET, self::BETA_PIN]] as [$organizationId, $ticketId, $okPinId]) {
            $this->transactions()->committedAs($organizationId, function () use ($ticketId, $okPinId): void {
                $this->commands()->createFromReport(new TicketId($ticketId), new \DateTimeImmutable(self::CREATED_AT));
                $itemId = $this->itemIdOf($okPinId);
                $this->commands()->markFixed($itemId, new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));
                $this->commands()->markOk($itemId, new UserId(self::MIA), new \DateTimeImmutable(self::NOW));
            });
        }
        // After the checklist round took number 1: Mia's findings round 2, with pin 2 before pin 1, one fixed, and Lena's
        // review round 3, whose item no command makes but only the round's kind keeps out of forRounds.
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::sharedRound(self::FINDINGS, 2, 'draft', self::MIA),
            self::sharedRound(self::REVIEW, 3, 'review', self::LENA),
            self::picture(self::ACME_FOODS, self::FINDINGS_PICTURE, self::FINDINGS, 0),
            self::picture(self::ACME_FOODS, self::REVIEW_PICTURE, self::REVIEW, 0),
            self::pin(self::ACME_FOODS, self::BADGE_PIN, self::FINDINGS_PICTURE, 2, 'Badge overlaps.'),
            self::pin(self::ACME_FOODS, self::CUT_TEXT_PIN, self::FINDINGS_PICTURE, 1, 'Text cut off.'),
            self::pin(self::ACME_FOODS, self::REVIEW_PIN, self::REVIEW_PICTURE, 1, 'Menu is hidden.'),
            self::item(self::BADGE_ITEM, self::FINDINGS, self::BADGE_PIN, ChecklistCommands::STATE_FIXED),
            self::item(self::CUT_TEXT_ITEM, self::FINDINGS, self::CUT_TEXT_PIN, ChecklistCommands::STATE_OPEN),
            self::item(self::REVIEW_ITEM, self::REVIEW, self::REVIEW_PIN, ChecklistCommands::STATE_OPEN),
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        // Only the outbox rows naming this test's tickets: other tests delete outbox rows at the same time.
        foreach ([self::TICKET, self::OTHER_TICKET] as $ticketId) {
            $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$ticketId.'%']);
        }
        parent::tearDown();
    }

    #[Test]
    public function theTicketsChecklistHasItsOwnItemsInPictureAndPinOrderWithOneOfThreeCheckedOk(): void
    {
        [$checklist, $roundId, $itemIds] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->queries()->forTicket(new TicketId(self::TICKET)),
            $this->connection()->fetchOne("SELECT id FROM rvw_round WHERE ticket_id = ? AND kind = 'checklist'", [self::TICKET]),
            [$this->itemIdOf(self::MISSING_PRICE_PIN), $this->itemIdOf(self::WRONG_TOTAL_PIN), $this->itemIdOf(self::SMALL_LOGO_PIN)],
        ]);

        self::assertIsString($roundId);
        self::assertEquals(new ChecklistView($roundId, 1, [
            new CheckItemView($itemIds[0], 1, '', ChecklistCommands::STATE_OK),
            new CheckItemView($itemIds[1], 2, 'Wrong total.', ChecklistCommands::STATE_OPEN),
            new CheckItemView($itemIds[2], 1, 'Logo too small.', ChecklistCommands::STATE_OPEN),
        ]), $checklist);
        self::assertSame([1, 3], [$checklist?->checkedOkCount, $checklist?->total]);
    }

    #[Test]
    public function aTicketWithoutAChecklistAndOneOnlyAnotherOrganizationHasHaveNone(): void
    {
        self::assertSame([null, null], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->queries()->forTicket(new TicketId(self::TICKET_WITHOUT_CHECKLIST)),
            $this->queries()->forTicket(new TicketId('0197a3c0-0000-7000-8000-0000000f8dff')),
        ]));
        $betaChecklist = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?ChecklistView => $this->queries()->forTicket(new TicketId(self::TICKET)));
        self::assertSame([1, 1], [$betaChecklist?->checkedOkCount, $betaChecklist?->total]);
    }

    #[Test]
    public function anItemsAccessFactsNameItsTicketRoundAndPinNumberInAnyCase(): void
    {
        [$facts, $upperCaseFacts, $itemId, $roundId] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $itemId = $this->itemIdOf(self::WRONG_TOTAL_PIN);

            return [
                $this->queries()->accessFactsOf($itemId),
                $this->queries()->accessFactsOf(strtoupper($itemId)),
                $itemId,
                $this->connection()->fetchOne("SELECT id FROM rvw_round WHERE ticket_id = ? AND kind = 'checklist'", [self::TICKET]),
            ];
        });

        self::assertIsString($roundId);
        $expected = new CheckItemAccessFacts(new OrganizationId(self::ACME_FOODS), $itemId, $roundId, new TicketId(self::TICKET), 2);
        self::assertEquals([$expected, $expected], [$facts, $upperCaseFacts]);
    }

    #[Test]
    public function aMalformedUnknownOrOtherOrganizationsItemHasNoFacts(): void
    {
        $betaItemId = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): string => $this->itemIdOf(self::BETA_PIN));

        self::assertSame([null, null, null, null], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->queries()->accessFactsOf('not-an-item'),
            $this->queries()->accessFactsOf($betaItemId."\n"),
            $this->queries()->accessFactsOf('0197a3c0-0000-7000-8000-0000000f8dfe'),
            $this->queries()->accessFactsOf($betaItemId),
        ]));
    }

    #[Test]
    public function withNoTransactionNothingIsRead(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->expectException(\LogicException::class);
            $this->queries()->forTicket(new TicketId(self::TICKET));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * The findings round is asked first, in upper case and twice, beside the report, which has no items, the review
     * round, whose item its kind keeps out, another ticket's checklist round and an unknown round: only the ticket's
     * checklist and findings rounds come back, by round number, each with its own items by picture position and pin number.
     */
    #[Test]
    public function theRoundsAskedHaveTheirOwnItemsByRoundNumberAndOnlyTheFindingsRoundIsFindings(): void
    {
        [$checklists, $checklistRoundId, $reportItemIds] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $checklistRoundId = $this->checklistRoundOf(self::TICKET);

            return [
                $this->roundChecklistQueries()->forRounds(new TicketId(self::TICKET), [
                    strtoupper(self::FINDINGS), self::REPORT, self::REVIEW, $this->checklistRoundOf(self::OTHER_TICKET),
                    '0197a3c0-0000-7000-8000-0000000f8dfd', $checklistRoundId, self::FINDINGS,
                ]),
                $checklistRoundId,
                [$this->itemIdOf(self::MISSING_PRICE_PIN), $this->itemIdOf(self::WRONG_TOTAL_PIN), $this->itemIdOf(self::SMALL_LOGO_PIN)],
            ];
        });

        self::assertEquals([
            $checklistRoundId => new RoundChecklist(new ChecklistView($checklistRoundId, 1, [
                new CheckItemView($reportItemIds[0], 1, '', ChecklistCommands::STATE_OK),
                new CheckItemView($reportItemIds[1], 2, 'Wrong total.', ChecklistCommands::STATE_OPEN),
                new CheckItemView($reportItemIds[2], 1, 'Logo too small.', ChecklistCommands::STATE_OPEN),
            ]), false, [
                $reportItemIds[0] => new CheckItemPicture(0, null),
                $reportItemIds[1] => new CheckItemPicture(0, null),
                $reportItemIds[2] => new CheckItemPicture(1, null),
            ]),
            self::FINDINGS => new RoundChecklist(new ChecklistView(self::FINDINGS, 2, [
                new CheckItemView(self::CUT_TEXT_ITEM, 1, 'Text cut off.', ChecklistCommands::STATE_OPEN),
                new CheckItemView(self::BADGE_ITEM, 2, 'Badge overlaps.', ChecklistCommands::STATE_FIXED),
            ]), true, [self::CUT_TEXT_ITEM => new CheckItemPicture(0, null), self::BADGE_ITEM => new CheckItemPicture(0, null)]),
        ], $checklists);
        self::assertSame([$checklistRoundId, self::FINDINGS], array_keys($checklists));
    }

    #[Test]
    public function anotherOrganizationsRoundsOfATicketWithTheSameIdAndNoRoundsHaveNoItems(): void
    {
        $betaChecklistRoundId = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): string => $this->checklistRoundOf(self::TICKET));

        self::assertSame([[], []], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->roundChecklistQueries()->forRounds(new TicketId(self::TICKET), [$betaChecklistRoundId]),
            $this->roundChecklistQueries()->forRounds(new TicketId(self::TICKET), []),
        ]));
        self::assertSame([], $this->transactions()->rolledBackAs(
            self::BETA_BAKERY,
            fn (): array => $this->roundChecklistQueries()->forRounds(new TicketId(self::TICKET), [self::FINDINGS]),
        ));
    }

    /**
     * forRounds' join with its organization conditions taken out, run as pinround_app: under Acme Foods as tenant,
     * row-level security alone hides Beta Bakery's checklist round on the same ticket id, whose items Beta's own tenant
     * gets.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsChecklistItemsOnTheTicketId(): void
    {
        [$betaChecklistRoundId, $betaItemIds] = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => [
            $this->checklistRoundOf(self::TICKET),
            [$this->itemIdOf(self::BETA_PIN)],
        ]);
        $itemIdsAs = fn (string $organizationId): array => $this->transactions()->rolledBackAs($organizationId, fn (): array => [
            $this->connection()->fetchOne('SELECT current_user'),
            $this->connection()->fetchFirstColumn(
                'SELECT item.id FROM rvw_round AS round'
                .' JOIN rvw_check_item AS item ON item.round_id = round.id'
                .' JOIN rvw_pin AS pin ON pin.id = item.pin_id'
                .' JOIN rvw_screenshot AS screenshot ON screenshot.id = pin.screenshot_id'
                .' WHERE round.ticket_id = CAST(:ticket AS uuid) AND round.id = ANY(CAST(:rounds AS uuid[])) AND round.kind IN (:checklist, :findings)'
                .' ORDER BY round.number, screenshot.position, pin.number',
                ['ticket' => self::TICKET, 'rounds' => '{'.$betaChecklistRoundId.'}', 'checklist' => 'checklist', 'findings' => 'draft'],
            ),
        ]);

        self::assertSame(['pinround_app', []], $itemIdsAs(self::ACME_FOODS));
        self::assertSame(['pinround_app', $betaItemIds], $itemIdsAs(self::BETA_BAKERY));
    }

    #[Test]
    public function aMalformedRoundIdIsRefusedBeforeAnyQuery(): void
    {
        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $this->expectException(\InvalidArgumentException::class);
            $this->roundChecklistQueries()->forRounds(new TicketId(self::TICKET), [self::FINDINGS, self::FINDINGS."\n"]);
        });
    }

    #[Test]
    public function withNoTransactionNoRoundsItemsAreRead(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->expectException(\LogicException::class);
            $this->roundChecklistQueries()->forRounds(new TicketId(self::TICKET), [self::FINDINGS]);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * Reads in the transaction already open, under its tenant.
     */
    private function checklistRoundOf(string $ticketId): string
    {
        $roundId = $this->connection()->fetchOne("SELECT id FROM rvw_round WHERE ticket_id = ? AND kind = 'checklist'", [$ticketId]);
        self::assertIsString($roundId);

        return $roundId;
    }

    /**
     * Reads in the transaction already open, under its tenant.
     */
    private function itemIdOf(string $pinId): string
    {
        $itemId = $this->connection()->fetchOne('SELECT id FROM rvw_check_item WHERE pin_id = ?', [$pinId]);
        self::assertIsString($itemId);

        return $itemId;
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function report(string $organizationId, string $roundId, string $ticketId): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId,
            'id' => $roundId,
            'ticket_id' => $ticketId,
            'number' => 0,
            'kind' => 'report',
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'closed',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * A round of Acme Foods' SHOP-1 shared after the report.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function sharedRound(string $roundId, int $number, string $kind, string $ownerId): array
    {
        return ['rvw_round', [
            'organization_id' => self::ACME_FOODS,
            'id' => $roundId,
            'ticket_id' => self::TICKET,
            'number' => $number,
            'kind' => $kind,
            'owner_id' => $ownerId,
            'shared_at' => '2027-03-16 09:00:00+00',
            'state' => 'open',
            'version' => 1,
            'created_at' => '2027-03-16 08:00:00+00',
        ]];
    }

    /**
     * An item of Acme Foods; one past "open" was last changed by Omar.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function item(string $itemId, string $roundId, string $pinId, string $state): array
    {
        $isChanged = ChecklistCommands::STATE_OPEN !== $state;

        return ['rvw_check_item', [
            'organization_id' => self::ACME_FOODS, 'id' => $itemId, 'round_id' => $roundId, 'pin_id' => $pinId, 'state' => $state,
            'changed_by' => $isChanged ? self::OMAR : null, 'changed_at' => $isChanged ? self::NOW : null, 'created_at' => '2027-03-16 09:00:00+00',
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function picture(string $organizationId, string $screenshotId, string $roundId, int $position): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId,
            'id' => $screenshotId,
            'round_id' => $roundId,
            'media_id' => self::MEDIA,
            'position' => $position,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $organizationId, string $pinId, string $screenshotId, int $number, string $note): array
    {
        return ['rvw_pin', [
            'organization_id' => $organizationId, 'id' => $pinId, 'screenshot_id' => $screenshotId, 'number' => $number, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => $note, 'source' => 'human', 'author_id' => self::LENA, 'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    private function queries(): ChecklistQueries
    {
        $queries = self::getContainer()->get('test.review_checklist_queries');
        self::assertInstanceOf(ChecklistQueries::class, $queries);

        return $queries;
    }

    private function roundChecklistQueries(): RoundChecklistQueries
    {
        $queries = self::getContainer()->get('test.review_checklist_queries');
        self::assertInstanceOf(RoundChecklistQueries::class, $queries, 'The container gives Review one checklist reader.');

        return $queries;
    }

    private function commands(): ChecklistCommands
    {
        $commands = self::getContainer()->get('test.review_checklist_commands');
        self::assertInstanceOf(ChecklistCommands::class, $commands);

        return $commands;
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
