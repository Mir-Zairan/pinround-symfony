<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Application\RoundsVisibleTo;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRoundQueries::previousHandOffRoundOf, the round whose pictures are a shared draft's Befores (ADR-0048), on rows
 * committed once per class in Acme Foods' Shop team (Lena leads it, Omar develops, Mia tests). The team-only ticket
 * holds the report (Round 0, two pictures, the first with a pin), Omar's shared draft Round 1 with the chain A (A1 at 0,
 * its retake A2 at 2) and B at 1, Lena's review Round 2 with two "after" pictures, the checklist Round 3, Omar's shared
 * draft Round 4 and his unshared draft. Another ticket holds its report, its checklist Round 1 and a shared draft
 * Round 3, which a read that forgot the ticket would take for the round before Round 4. A third ticket has no report,
 * only a shared draft Round 1. Rows go in out of position order. Every time is fixed in March 2027.
 */
final class DbalRoundQueriesPreviousHandOffRoundTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000030a001';
    private const string LENA = '0197a3c0-0000-7000-8000-00000030a011';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000030a012';
    private const string MIA = '0197a3c0-0000-7000-8000-00000030a013';
    private const string OUTSIDER = '0197a3c0-0000-7000-8000-00000030a014';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000030a021';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000030a022';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000030a031';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000030a032';
    private const string TICKET_WITHOUT_REPORT = '0197a3c0-0000-7000-8000-00000030a033';

    private const string REPORT = '0197a3c0-0000-7000-8000-00000030a041';
    private const string ROUND_ONE = '0197a3c0-0000-7000-8000-00000030a042';
    private const string REVIEW_ROUND_TWO = '0197a3c0-0000-7000-8000-00000030a043';
    private const string CHECKLIST_ROUND_THREE = '0197a3c0-0000-7000-8000-00000030a044';
    private const string ROUND_FOUR = '0197a3c0-0000-7000-8000-00000030a045';
    private const string UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-00000030a046';
    private const string OTHER_REPORT = '0197a3c0-0000-7000-8000-00000030a047';
    private const string OTHER_CHECKLIST = '0197a3c0-0000-7000-8000-00000030a048';
    private const string OTHER_ROUND_THREE = '0197a3c0-0000-7000-8000-00000030a049';
    private const string ROUND_ONE_WITHOUT_REPORT = '0197a3c0-0000-7000-8000-00000030a04b';

    private const string A1 = '0197a3c0-0000-7000-8000-00000030a051';
    private const string B = '0197a3c0-0000-7000-8000-00000030a052';
    private const string A2 = '0197a3c0-0000-7000-8000-00000030a053';
    private const string AFTER_AT_0 = '0197a3c0-0000-7000-8000-00000030a054';
    private const string AFTER_AT_1 = '0197a3c0-0000-7000-8000-00000030a055';
    private const string REPORT_SHOT = '0197a3c0-0000-7000-8000-00000030a056';
    private const string ROUND_FOUR_SHOT = '0197a3c0-0000-7000-8000-00000030a057';
    private const string UNSHARED_SHOT = '0197a3c0-0000-7000-8000-00000030a058';
    private const string OTHER_SHOT = '0197a3c0-0000-7000-8000-00000030a059';
    private const string REPORT_SECOND_SHOT = '0197a3c0-0000-7000-8000-00000030a05a';
    private const string OTHER_REPORT_SHOT = '0197a3c0-0000-7000-8000-00000030a05b';
    private const string WITHOUT_REPORT_SHOT = '0197a3c0-0000-7000-8000-00000030a05c';

    private const string A1_PIN = '0197a3c0-0000-7000-8000-00000030a061';
    private const string B_PIN = '0197a3c0-0000-7000-8000-00000030a062';
    private const string A2_PIN = '0197a3c0-0000-7000-8000-00000030a063';
    private const string REPORT_PIN = '0197a3c0-0000-7000-8000-00000030a064';

    private const string MEDIA = '0197a3c0-0000-7000-8000-00000030a081';

    private const string CREATED_AT = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        $tickets = new TicketRows(self::migrator(), self::tenantContext());
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, 1, 'team', self::SHOP_TEAM, self::MIA);
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, 2, 'team', self::SHOP_TEAM, self::MIA);
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::TICKET_WITHOUT_REPORT, self::SHOP_PROJECT, 3, 'team', self::SHOP_TEAM, self::MIA);

        $round = static fn (string $id, string $ticketId, ?int $number, string $kind, string $ownerId, string $state = 'open'): array => ['rvw_round', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'ticket_id' => $ticketId, 'number' => $number, 'kind' => $kind,
            'owner_id' => $ownerId, 'shared_at' => null === $number ? null : self::SHARED_AT, 'state' => $state, 'version' => 1,
            'created_at' => self::CREATED_AT,
        ]];
        $screenshot = static fn (string $id, string $roundId, int $position, ?string $retakeOf = null): array => ['rvw_screenshot', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'round_id' => $roundId, 'media_id' => self::MEDIA, 'position' => $position,
            'title' => 'Picture at '.$position, 'retake_of' => $retakeOf, 'created_at' => self::CREATED_AT,
        ]];
        $pin = static fn (string $id, string $screenshotId, string $note): array => ['rvw_pin', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'screenshot_id' => $screenshotId, 'number' => 1, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => $note, 'source' => 'human', 'author_id' => self::OMAR, 'created_at' => self::CREATED_AT,
        ]];
        // A retake's first try goes in before it (rvw_screenshot_retake_of_fkey); the positions are not in order.
        new RoundRows(self::migrator(), self::tenantContext())->addAllOf(self::ACME_FOODS, [
            $round(self::REPORT, self::TICKET, 0, 'report', self::MIA),
            $round(self::ROUND_ONE, self::TICKET, 1, 'draft', self::OMAR, 'closed'),
            $round(self::REVIEW_ROUND_TWO, self::TICKET, 2, 'review', self::LENA, 'closed'),
            $round(self::CHECKLIST_ROUND_THREE, self::TICKET, 3, 'checklist', self::MIA),
            $round(self::ROUND_FOUR, self::TICKET, 4, 'draft', self::OMAR),
            $round(self::UNSHARED_DRAFT, self::TICKET, null, 'draft', self::OMAR),
            $round(self::OTHER_REPORT, self::OTHER_TICKET, 0, 'report', self::MIA),
            $round(self::OTHER_CHECKLIST, self::OTHER_TICKET, 1, 'checklist', self::MIA),
            $round(self::OTHER_ROUND_THREE, self::OTHER_TICKET, 3, 'draft', self::OMAR),
            $round(self::ROUND_ONE_WITHOUT_REPORT, self::TICKET_WITHOUT_REPORT, 1, 'draft', self::OMAR),
            $screenshot(self::B, self::ROUND_ONE, 1),
            $screenshot(self::A1, self::ROUND_ONE, 0),
            $screenshot(self::A2, self::ROUND_ONE, 2, self::A1),
            $screenshot(self::AFTER_AT_1, self::REVIEW_ROUND_TWO, 1),
            $screenshot(self::AFTER_AT_0, self::REVIEW_ROUND_TWO, 0),
            $screenshot(self::REPORT_SECOND_SHOT, self::REPORT, 1),
            $screenshot(self::REPORT_SHOT, self::REPORT, 0),
            $screenshot(self::OTHER_REPORT_SHOT, self::OTHER_REPORT, 0),
            $screenshot(self::WITHOUT_REPORT_SHOT, self::ROUND_ONE_WITHOUT_REPORT, 0),
            $screenshot(self::ROUND_FOUR_SHOT, self::ROUND_FOUR, 0),
            $screenshot(self::UNSHARED_SHOT, self::UNSHARED_DRAFT, 0),
            $screenshot(self::OTHER_SHOT, self::OTHER_ROUND_THREE, 0),
            $pin(self::A2_PIN, self::A2, 'Still cut off on the latest try'),
            $pin(self::A1_PIN, self::A1, 'Cut off on the first try'),
            $pin(self::B_PIN, self::B, 'The basket'),
            $pin(self::REPORT_PIN, self::REPORT_SHOT, 'The total is doubled'),
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

    /**
     * Round 2's Befores are Round 1's latest tries, A2 before B as their chains' first tries stand, each with its own
     * pin; A1 and its pin are not among them.
     */
    #[Test]
    public function theRoundBeforeRoundTwoIsRoundOneWithTheLatestTryOfEachChainAndItsPins(): void
    {
        $previous = $this->previousHandOffRoundFor(self::TICKET, self::teamMember(self::MIA), 2);

        self::assertNotNull($previous);
        self::assertSame([self::ROUND_ONE, 1, 'draft', 'closed'], [$previous->id, $previous->number, $previous->kind, $previous->state]);
        self::assertNotSame([], $previous->screenshots);
        self::assertSame(
            [[self::A2, [self::A2_PIN]], [self::B, [self::B_PIN]]],
            array_map(static fn (RoundScreenshotView $screenshot): array => [
                $screenshot->id,
                array_map(static fn (RoundPinView $pin): string => $pin->id, $screenshot->pins),
            ], $previous->screenshots),
        );
    }

    /**
     * The checklist Round 3 is no hand-off, so Round 4's round before is the review Round 2, with its "after" pictures
     * by position; the other ticket's Round 3 is not this ticket's.
     */
    #[Test]
    public function theRoundBeforeSkipsTheChecklistRoundAndIgnoresAnotherTicketsRounds(): void
    {
        foreach ([3, 4] as $number) {
            $previous = $this->previousHandOffRoundFor(self::TICKET, self::teamMember(self::MIA), $number);

            self::assertNotNull($previous, 'Round '.$number);
            self::assertSame(self::REVIEW_ROUND_TWO, $previous->id, 'Round '.$number);
            self::assertSame([self::AFTER_AT_0, self::AFTER_AT_1], array_map(static fn (RoundScreenshotView $screenshot): string => $screenshot->id, $previous->screenshots));
        }
    }

    /**
     * With no hand-off round before it, Round 1's Befores are the report's pictures, by position, the first with its
     * pin; neither the other ticket's report nor any round above Round 1 is taken.
     */
    #[Test]
    public function theRoundBeforeTheFirstSharedDraftIsTheReportWithItsPicturesAndPins(): void
    {
        $previous = $this->previousHandOffRoundFor(self::TICKET, self::teamMember(self::MIA), 1);

        self::assertNotNull($previous);
        self::assertSame([self::REPORT, 0, 'report'], [$previous->id, $previous->number, $previous->kind]);
        self::assertSame(
            [[self::REPORT_SHOT, [self::REPORT_PIN]], [self::REPORT_SECOND_SHOT, []]],
            array_map(static fn (RoundScreenshotView $screenshot): array => [
                $screenshot->id,
                array_map(static fn (RoundPinView $pin): string => $pin->id, $screenshot->pins),
            ], $previous->screenshots),
        );
    }

    /**
     * The checklist Round 1 below the other ticket's draft Round 3 is no hand-off, so the report is its round before.
     */
    #[Test]
    public function theReportIsTheRoundBeforeWhenOnlyTheChecklistRoundStandsBetween(): void
    {
        $previous = $this->previousHandOffRoundFor(self::OTHER_TICKET, self::teamMember(self::MIA), 3);

        self::assertNotNull($previous);
        self::assertSame(self::OTHER_REPORT, $previous->id);
        self::assertSame([self::OTHER_REPORT_SHOT], array_map(static fn (RoundScreenshotView $screenshot): string => $screenshot->id, $previous->screenshots));
    }

    /**
     * @return iterable<string, array{string, int}> ticket, number
     */
    public static function roundsWithNoRoundBefore(): iterable
    {
        yield 'Round 1 of a ticket with no report' => [self::TICKET_WITHOUT_REPORT, 1];
        yield 'Round 0' => [self::TICKET, 0];
    }

    #[Test]
    #[DataProvider('roundsWithNoRoundBefore')]
    public function aRoundWithNeitherAHandOffRoundNorAReportBeforeItHasNone(string $ticketId, int $number): void
    {
        self::assertNull($this->previousHandOffRoundFor($ticketId, self::teamMember(self::MIA), $number));
    }

    /**
     * The read's "deleted_at IS NULL" has no row to meet: a deleted draft keeps no number (rvw_round_deleted_check), so
     * none can stand between the other ticket's checklist Round 1 and its draft Round 3, whose round before stays the
     * report. Should the schema ever take one, this fails and asks for the read to be tested against it.
     */
    #[Test]
    public function noDeletedSharedDraftCanStandBetweenTwoRoundsSoTheRoundBeforeStaysAsItWas(): void
    {
        $deletedSharedDraft = ['rvw_round', [
            'organization_id' => self::ACME_FOODS, 'id' => '0197a3c0-0000-7000-8000-00000030a04a', 'ticket_id' => self::OTHER_TICKET,
            'number' => 2, 'kind' => 'draft', 'owner_id' => self::OMAR, 'shared_at' => self::SHARED_AT, 'state' => 'closed',
            'version' => 1, 'created_at' => self::CREATED_AT, 'deleted_at' => '2027-03-17 09:00:00+00',
        ]];

        try {
            new RoundRows(self::migrator(), self::tenantContext())->addAllOf(self::ACME_FOODS, [$deletedSharedDraft]);
            self::fail('The schema took a deleted shared draft; test previousHandOffRoundOf with one between two rounds.');
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState(), 'A CHECK refuses it.');
            self::assertStringContainsString('rvw_round_deleted_check', $refusal->getMessage());
        }

        self::assertSame(self::OTHER_REPORT, $this->previousHandOffRoundFor(self::OTHER_TICKET, self::teamMember(self::MIA), 3)?->id);
    }

    /**
     * A team-only ticket is not the outsider's to see, so nothing of its rounds comes back, the report neither: only the
     * visibility check runs.
     */
    #[Test]
    public function aViewerWhoMayNotSeeTheTicketGetsNoRoundAndOnlyTheVisibilityCheckRuns(): void
    {
        $outsider = TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OUTSIDER), [], false, null);

        foreach ([4 => self::REVIEW_ROUND_TWO, 1 => self::REPORT] as $number => $roundBefore) {
            [$previous, $queryCount] = $this->previousHandOffRoundAndQueryCountFor(self::TICKET, $outsider, $number);

            self::assertNull($previous, 'Round '.$number);
            self::assertSame(1, $queryCount, 'Round '.$number);
            self::assertSame($roundBefore, $this->previousHandOffRoundFor(self::TICKET, self::teamMember(self::LENA), $number)?->id, 'Lena of the team gets the round before Round '.$number.'.');
        }
    }

    /**
     * Four queries whatever the screenshots and pins (visibility, round, screenshots, pins), the report's too, and none
     * for Round 0.
     */
    #[Test]
    public function theRoundBeforeCostsFourQueriesAndRoundZeroNone(): void
    {
        foreach ([2 => self::ROUND_ONE, 1 => self::REPORT] as $number => $roundBefore) {
            [$previous, $queryCount] = $this->previousHandOffRoundAndQueryCountFor(self::TICKET, self::teamMember(self::MIA), $number);
            self::assertSame($roundBefore, $previous?->id, 'Round '.$number);
            self::assertSame(4, $queryCount, 'Round '.$number);
        }

        [$none, $noQueries] = $this->previousHandOffRoundAndQueryCountFor(self::TICKET, self::teamMember(self::MIA), 0);
        self::assertNull($none);
        self::assertSame(0, $noQueries);
    }

    #[Test]
    public function aVisibilityOfAnotherOrganizationIsRefusedBeforeAnyQuery(): void
    {
        $visibility = TicketVisibility::for(new OrganizationId('0197a3c0-0000-7000-8000-00000030a002'), new UserId(self::MIA), [], true, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The ticket visibility given is not one of the tenant\'s organization.');

        $this->previousHandOffRoundFor(self::TICKET, $visibility, 4);
    }

    private static function teamMember(string $userId): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId($userId), [new TeamId(self::SHOP_TEAM)], false, null);
    }

    private function previousHandOffRoundFor(string $ticketId, TicketVisibility $viewer, int $number): ?RoundView
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?RoundView => $this->roundsVisibleTo()->previousHandOffRoundOf(new TicketId($ticketId), $viewer, $number));
    }

    /**
     * @return array{RoundView|null, int}
     */
    private function previousHandOffRoundAndQueryCountFor(string $ticketId, TicketVisibility $viewer, int $number): array
    {
        $debugData = $this->debugData();

        return self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($ticketId, $viewer, $number, $debugData): array {
            $debugData->reset();
            $previous = $this->roundsVisibleTo()->previousHandOffRoundOf(new TicketId($ticketId), $viewer, $number);

            return [$previous, self::queryCountOf($debugData)];
        });
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS);
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS);
    }

    private function roundsVisibleTo(): RoundsVisibleTo
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof RoundsVisibleTo);

        return $roundQueries;
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
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
