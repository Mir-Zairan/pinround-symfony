<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Application\RoundsVisibleTo;
use App\Review\Infrastructure\Doctrine\DbalRoundQueries;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The screenshot count each round of a ticket comes with from DbalRoundQueries, for the ticket page's round rows
 * (P14.T7), on rows committed once per class in Acme Foods' Shop team (Lena leads it, Omar develops, Mia tests).
 * Round 0 holds 2 screenshots; review Round 1 none; review Round 2 one; Omar's shared draft Round 3 the chain A (A1 at
 * 0, its retake A2 at 2), B at 1 and C at 3, so 3 latest tries; Omar's unshared draft the chain D (D1, its retake D2),
 * so 1. Beta Bakery holds a round with Round 1's id on the same ticket id, with 2 screenshots of its own. Rows go in out
 * of position order. Every time is fixed in March 2027; nothing reads the clock.
 */
final class DbalRoundQueriesScreenshotCountTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000016f001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000016f002';
    private const string LENA = '0197a3c0-0000-7000-8000-00000016f011';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000016f012';
    private const string MIA = '0197a3c0-0000-7000-8000-00000016f013';
    private const string BEN = '0197a3c0-0000-7000-8000-00000016f014';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000016f021';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000016f022';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000016f031';

    private const string REPORT = '0197a3c0-0000-7000-8000-00000016f041';
    private const string EMPTY_REVIEW = '0197a3c0-0000-7000-8000-00000016f042';
    private const string REVIEW = '0197a3c0-0000-7000-8000-00000016f043';
    private const string SHARED_DRAFT = '0197a3c0-0000-7000-8000-00000016f044';
    private const string UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-00000016f045';

    private const string REPORT_FIRST = '0197a3c0-0000-7000-8000-00000016f051';
    private const string REPORT_SECOND = '0197a3c0-0000-7000-8000-00000016f052';
    private const string REVIEW_AFTER = '0197a3c0-0000-7000-8000-00000016f053';
    private const string A1 = '0197a3c0-0000-7000-8000-00000016f054';
    private const string A2 = '0197a3c0-0000-7000-8000-00000016f055';
    private const string B = '0197a3c0-0000-7000-8000-00000016f056';
    private const string C = '0197a3c0-0000-7000-8000-00000016f057';
    private const string D1 = '0197a3c0-0000-7000-8000-00000016f058';
    private const string D2 = '0197a3c0-0000-7000-8000-00000016f059';
    private const string BAKERY_FIRST = '0197a3c0-0000-7000-8000-00000016f05a';
    private const string BAKERY_SECOND = '0197a3c0-0000-7000-8000-00000016f05b';

    private const string REPORT_PIN = '0197a3c0-0000-7000-8000-00000016f061';
    private const string MEDIA = '0197a3c0-0000-7000-8000-00000016f071';

    private const string CREATED_AT = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';

    /** Every viewer's user id. */
    private const array VIEWERS = ['Lena' => self::LENA, 'Mia' => self::MIA, 'Omar' => self::OMAR];

    /** The count of every shared round of the ticket, by number. */
    private const array SHARED_ROUND_COUNTS = [[self::REPORT, 2], [self::EMPTY_REVIEW, 0], [self::REVIEW, 1], [self::SHARED_DRAFT, 3]];

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        new TicketRows(self::migrator(), self::tenantContext())->addWithAudienceOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, 1, 'all', self::SHOP_TEAM, self::MIA);

        $round = static fn (string $organizationId, string $id, ?int $number, string $kind, string $ownerId, ?string $sharedAt): array => ['rvw_round', [
            'organization_id' => $organizationId, 'id' => $id, 'ticket_id' => self::TICKET, 'number' => $number, 'kind' => $kind,
            'owner_id' => $ownerId, 'shared_at' => $sharedAt, 'state' => 'open', 'version' => 1, 'created_at' => self::CREATED_AT,
        ]];
        $screenshot = static fn (string $organizationId, string $id, string $roundId, int $position, ?string $retakeOf = null): array => ['rvw_screenshot', [
            'organization_id' => $organizationId, 'id' => $id, 'round_id' => $roundId, 'media_id' => self::MEDIA, 'position' => $position,
            'title' => 'Shot at '.$position, 'retake_of' => $retakeOf, 'created_at' => self::CREATED_AT,
        ]];
        // A retake's first try goes in before it (rvw_screenshot_retake_of_fkey); the positions are not in order.
        new RoundRows(self::migrator(), self::tenantContext())->addAllOf(self::ACME_FOODS, [
            $round(self::ACME_FOODS, self::UNSHARED_DRAFT, null, 'draft', self::OMAR, null),
            $round(self::ACME_FOODS, self::SHARED_DRAFT, 3, 'draft', self::OMAR, self::SHARED_AT),
            $round(self::ACME_FOODS, self::REVIEW, 2, 'review', self::LENA, self::SHARED_AT),
            $round(self::ACME_FOODS, self::EMPTY_REVIEW, 1, 'review', self::LENA, self::SHARED_AT),
            $round(self::ACME_FOODS, self::REPORT, 0, 'report', self::MIA, self::SHARED_AT),
            $screenshot(self::ACME_FOODS, self::REPORT_SECOND, self::REPORT, 1),
            $screenshot(self::ACME_FOODS, self::REPORT_FIRST, self::REPORT, 0),
            $screenshot(self::ACME_FOODS, self::REVIEW_AFTER, self::REVIEW, 0),
            $screenshot(self::ACME_FOODS, self::C, self::SHARED_DRAFT, 3),
            $screenshot(self::ACME_FOODS, self::A1, self::SHARED_DRAFT, 0),
            $screenshot(self::ACME_FOODS, self::A2, self::SHARED_DRAFT, 2, self::A1),
            $screenshot(self::ACME_FOODS, self::B, self::SHARED_DRAFT, 1),
            $screenshot(self::ACME_FOODS, self::D1, self::UNSHARED_DRAFT, 0),
            $screenshot(self::ACME_FOODS, self::D2, self::UNSHARED_DRAFT, 1, self::D1),
            ['rvw_pin', [
                'organization_id' => self::ACME_FOODS, 'id' => self::REPORT_PIN, 'screenshot_id' => self::REPORT_FIRST, 'number' => 1,
                'x_pct' => '40.00', 'y_pct' => '60.00', 'note' => 'The total', 'source' => 'human', 'author_id' => self::MIA, 'created_at' => self::CREATED_AT,
            ]],
        ]);
        // rvw_round's key is (organization_id, id), so Beta Bakery may hold a round with Acme's id on Acme's ticket id.
        new RoundRows(self::migrator(), self::tenantContext())->addAllOf(self::BETA_BAKERY, [
            $round(self::BETA_BAKERY, self::EMPTY_REVIEW, 1, 'review', self::BEN, self::SHARED_AT),
            $screenshot(self::BETA_BAKERY, self::BAKERY_FIRST, self::EMPTY_REVIEW, 0),
            $screenshot(self::BETA_BAKERY, self::BAKERY_SECOND, self::EMPTY_REVIEW, 1),
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
     * @return iterable<string, array{string, list<array{string, int}>}> viewer, and each round's id and count in order
     */
    public static function countsByViewer(): iterable
    {
        yield 'Omar, with his unshared draft of one chain of two tries' => ['Omar', [...self::SHARED_ROUND_COUNTS, [self::UNSHARED_DRAFT, 1]]];
        yield 'Lena, the lead' => ['Lena', self::SHARED_ROUND_COUNTS];
        yield 'Mia, the tester' => ['Mia', self::SHARED_ROUND_COUNTS];
    }

    /**
     * The shared draft counts A2, B and C, not the earlier try A1; the empty review round counts none of Beta Bakery's
     * two screenshots on a round with its id; a review round counts its own though it is loaded with none.
     *
     * @param list<array{string, int}> $expectedCounts
     */
    #[Test]
    #[DataProvider('countsByViewer')]
    public function eachRoundOfTheTicketCountsTheLatestTryOfEachOfItsScreenshots(string $viewer, array $expectedCounts): void
    {
        $rounds = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->roundQueries()->forTicket(new TicketId(self::TICKET), new UserId(self::VIEWERS[$viewer]))->rounds);

        self::assertSame($expectedCounts, self::countsOf($rounds));
        self::assertSame([], self::roundNamed($rounds, self::REVIEW)->screenshots, 'A review round is a row: it is counted, not loaded.');
    }

    /**
     * The ticket page reads through forVisibleTo: the same rounds and counts, but only the report and the viewer's own
     * unshared draft come with their screenshots. The shared draft, a row on the page, comes with none, in the same four
     * queries.
     */
    #[Test]
    public function forVisibleToCountsEveryRoundAndLoadsOnlyTheReportsAndTheUnsharedDraftsScreenshots(): void
    {
        $visibility = TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM)], false, null);

        [$rounds, $queryCount] = self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($visibility): array {
            $this->debugData()->reset();
            $rounds = $this->roundsVisibleTo()->forVisibleTo(new TicketId(self::TICKET), $visibility)->rounds;

            return [$rounds, self::queryCountOf($this->debugData())];
        });

        self::assertSame([...self::SHARED_ROUND_COUNTS, [self::UNSHARED_DRAFT, 1]], self::countsOf($rounds));
        self::assertSame(
            [
                self::REPORT => [self::REPORT_FIRST, self::REPORT_SECOND],
                self::EMPTY_REVIEW => [],
                self::REVIEW => [],
                self::SHARED_DRAFT => [],
                self::UNSHARED_DRAFT => [self::D1, self::D2],
            ],
            array_combine(
                array_map(static fn (RoundView $round): string => $round->id, $rounds),
                array_map(static fn (RoundView $round): array => array_map(static fn (RoundScreenshotView $screenshot): string => $screenshot->id, $round->screenshots), $rounds),
            ),
        );
        self::assertSame(4, $queryCount, 'The visibility check, the rounds with their counts, the screenshots and their pins.');
    }

    /**
     * The round page and the draft card read one round with its screenshots, which they count themselves.
     */
    #[Test]
    public function aRoundReadAloneComesWithNoCount(): void
    {
        $visibility = TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM)], false, null);

        [$sharedRound, $draft] = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->roundsVisibleTo()->sharedRoundOf(new TicketId(self::TICKET), $visibility, 3),
            $this->roundQueries()->draftOf(self::UNSHARED_DRAFT, new UserId(self::OMAR)),
        ]);

        self::assertNotNull($sharedRound);
        self::assertNotNull($draft);
        self::assertSame([[self::SHARED_DRAFT, 3, null], [self::UNSHARED_DRAFT, 2, null]], [
            [$sharedRound->id, \count($sharedRound->screenshots), $sharedRound->screenshotCount],
            [$draft->id, \count($draft->screenshots), $draft->screenshotCount],
        ]);
    }

    /**
     * @param list<RoundView> $rounds
     *
     * @return list<array{string, int|null}>
     */
    private static function countsOf(array $rounds): array
    {
        self::assertNotSame([], $rounds);

        return array_map(static fn (RoundView $round): array => [$round->id, $round->screenshotCount], $rounds);
    }

    /**
     * @param list<RoundView> $rounds
     */
    private static function roundNamed(array $rounds, string $roundId): RoundView
    {
        $matches = array_values(array_filter($rounds, static fn (RoundView $round): bool => $roundId === $round->id));
        self::assertCount(1, $matches);

        return $matches[0];
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS);
    }

    private function roundQueries(): RoundQueries
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof DbalRoundQueries);

        return $roundQueries;
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
