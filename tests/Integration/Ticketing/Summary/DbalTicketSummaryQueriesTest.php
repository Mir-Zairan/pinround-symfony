<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Summary;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketSummaryHistory;
use App\Ticketing\Api\TicketSummaryQueries;
use App\Ticketing\Api\TicketSummaryView;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TicketSummaryQueries as a page gets it. In Acme Foods, SHOP-7 is restricted to the Shop team and reported by Mia; it
 * has a current summary older than every earlier one, and 23 earlier ones added out of order, two of them in the same
 * second. SHOP-8, for everyone, has a summary that SHOP-7's history must leave out; SHOP-9, for everyone, has none.
 * Omar is in no team.
 */
final class DbalTicketSummaryQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f7d01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f7d02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f7d03';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f7d04';
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f7d05';
    private const string SHOP_8 = '0197a3c0-0000-7000-8000-0000000f7d06';
    private const string SHOP_9 = '0197a3c0-0000-7000-8000-0000000f7d07';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7d11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f7d12';
    private const string CURRENT_SUMMARY = '0197a3c0-0000-7000-8000-0000000f7e00';
    private const string SHOP_8_SUMMARY = '0197a3c0-0000-7000-8000-0000000f7f00';
    private const int EARLIER_SUMMARY_COUNT = 23;
    /** Earlier summaries 10 and 11 share a second; 11 has the higher id and is added after 10. */
    private const int TIED_SUMMARY = 11;

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::SHOP_7, self::SHOP_PROJECT, 7, 'team', self::SHOP_TEAM, self::MIA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::SHOP_8, self::SHOP_PROJECT, 8, 'all', self::SHOP_TEAM, self::MIA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::SHOP_9, self::SHOP_PROJECT, 9, 'all', self::SHOP_TEAM, self::MIA);

        // Added in this order, so the order the query gives is not the order the rows were written.
        foreach ([5, 17, 1, 23, 10, 3, 20, 8, 11, 14, 2, 22, 6, 19, 9, 4, 16, 12, 21, 7, 15, 13, 18] as $number) {
            $this->summaryRows()->addOf(self::ACME_FOODS, self::earlierSummaryId($number), self::SHOP_7, 'Earlier summary '.$number, false, self::earlierCreatedAt($number));
        }
        $this->summaryRows()->addOf(self::ACME_FOODS, self::CURRENT_SUMMARY, self::SHOP_7, 'Current summary', true, '2027-02-27 09:00:00+00');
        $this->summaryRows()->addOf(self::ACME_FOODS, self::SHOP_8_SUMMARY, self::SHOP_8, 'Summary of SHOP-8', true, '2027-03-02 09:00:00+00');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theCurrentSummaryComesFirstThenTheTwentyNewestEarlierOnesAndTheCountOfAll(): void
    {
        $history = $this->historyOf(self::SHOP_7, self::visibilityOf(self::MIA, []));

        self::assertNotNull($history);
        self::assertNotNull($history->current);
        self::assertSame(
            [self::CURRENT_SUMMARY, 'Current summary', 'human', null, null, null, '2027-02-27T09:00:00+00:00'],
            self::fieldsOf($history->current),
        );
        $expectedEarlierNumbers = [23, 22, 21, 20, 19, 18, 17, 16, 15, 14, 13, 12, self::TIED_SUMMARY, 10, 9, 8, 7, 6, 5, 4];
        self::assertSame(
            array_map(self::earlierSummaryId(...), $expectedEarlierNumbers),
            array_map(static fn (TicketSummaryView $view): string => $view->id, $history->earlier),
        );
        self::assertSame(self::earlierCreatedAt(10), self::earlierCreatedAt(self::TIED_SUMMARY), 'Only a shared second lets the id break the tie.');
        self::assertSame(self::EARLIER_SUMMARY_COUNT, $history->earlierCount);
    }

    #[Test]
    public function aViewerWhoMayNotSeeTheTicketGetsNoHistoryButOneInItsTeamDoes(): void
    {
        $historyOfOmar = $this->historyOf(self::SHOP_7, self::visibilityOf(self::OMAR, []));
        $historyOfOmarInShop = $this->historyOf(self::SHOP_7, self::visibilityOf(self::OMAR, [new TeamId(self::SHOP_TEAM)]));

        self::assertNull($historyOfOmar);
        self::assertNotNull($historyOfOmarInShop);
        self::assertSame(self::CURRENT_SUMMARY, $historyOfOmarInShop->current?->id);
    }

    #[Test]
    public function aVisibleTicketWithNoSummaryHasAnEmptyHistory(): void
    {
        $history = $this->historyOf(self::SHOP_9, self::visibilityOf(self::OMAR, []));

        self::assertEquals(new TicketSummaryHistory(null, [], 0), $history);
    }

    #[Test]
    public function aTicketWithEarlierSummariesAndNoCurrentOneStillGetsTwentyAndTheCountOfAll(): void
    {
        foreach (range(1, TicketSummaryQueries::EARLIER_LIMIT + 1) as $number) {
            $this->summaryRows()->addOf(self::ACME_FOODS, \sprintf('0197a3c0-0000-7000-8000-0000000f7a%02x', $number), self::SHOP_9, 'Old '.$number, false, self::earlierCreatedAt($number));
        }

        $history = $this->historyOf(self::SHOP_9, self::visibilityOf(self::OMAR, []));

        self::assertNotNull($history);
        self::assertSame(
            [null, range(TicketSummaryQueries::EARLIER_LIMIT + 1, 2), TicketSummaryQueries::EARLIER_LIMIT + 1],
            [$history->current, array_map(static fn (TicketSummaryView $view): int => (int) substr($view->text, 4), $history->earlier), $history->earlierCount],
        );
    }

    #[Test]
    public function aTicketWithOnlyACurrentSummaryHasNoEarlierOnes(): void
    {
        $history = $this->historyOf(self::SHOP_8, self::visibilityOf(self::OMAR, []));

        self::assertNotNull($history);
        self::assertSame([self::SHOP_8_SUMMARY, [], 0], [$history->current?->id, $history->earlier, $history->earlierCount]);
    }

    #[Test]
    public function anUnknownTicketOrOneReadUnderAnotherOrganizationHasNoHistory(): void
    {
        $unknownTicketHistory = $this->historyOf('0197a3c0-0000-7000-8000-0000000f7d99', self::visibilityOf(self::MIA, []));
        $historyUnderBeta = $this->transactions()->committedAs(
            self::BETA_BAKERY,
            fn (): ?TicketSummaryHistory => $this->queries()->historyOf(new TicketId(self::SHOP_8), self::visibilityOf(self::OMAR, [])),
        );

        self::assertSame([null, null], [$unknownTicketHistory, $historyUnderBeta]);
    }

    #[Test]
    public function aReadOutsideATransactionIsRefused(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessageIsOrContains('Ticket summaries are read in a transaction under the organization as tenant; none is open.');
            $this->queries()->historyOf(new TicketId(self::SHOP_7), self::visibilityOf(self::MIA, []));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function historyOf(string $ticketId, TicketVisibility $visibility): ?TicketSummaryHistory
    {
        return $this->transactions()->committedAs(
            self::ACME_FOODS,
            fn (): ?TicketSummaryHistory => $this->queries()->historyOf(new TicketId($ticketId), $visibility),
        );
    }

    /**
     * @param list<TeamId> $teamIds
     */
    private static function visibilityOf(string $viewerId, array $teamIds): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId($viewerId), $teamIds, false, null);
    }

    /**
     * @return list<string|null>
     */
    private static function fieldsOf(TicketSummaryView $view): array
    {
        return [$view->id, $view->text, $view->source, $view->authorId?->toString(), $view->model, $view->promptVersion, $view->createdAt->format(\DATE_ATOM)];
    }

    private static function earlierSummaryId(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000f7e%02x', $number);
    }

    private static function earlierCreatedAt(int $number): string
    {
        $minute = self::TIED_SUMMARY === $number ? 10 : $number;

        return \sprintf('2027-03-01 09:%02d:00+00', $minute);
    }

    private function queries(): TicketSummaryQueries
    {
        $queries = self::getContainer()->get('test.ticketing_ticket_summary_queries');
        self::assertInstanceOf(TicketSummaryQueries::class, $queries);

        return $queries;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function summaryRows(): SummaryRows
    {
        return new SummaryRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
