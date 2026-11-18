<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundQueries;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRoundQueries::draftScreenshotOf against the real database on rows committed once per class. The ticket has its
 * report (Round 0), Lena's shared draft as Round 1 and, above it, more review rounds than RoundQueries shows, so that
 * draft is past the cap; then Omar's and Mia's unshared drafts and Mia's deleted one. Another ticket has a draft of
 * Omar's, and Beta Bakery holds Omar's draft under the same ticket id. Every time is fixed in March 2027.
 */
final class DbalDraftScreenshotOfTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6901';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f6902';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6911';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f6912';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6913';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f6921';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f6922';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f6931';
    private const string LENAS_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f6932';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-0000000f6933';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f6934';
    private const string MIAS_DELETED_DRAFT = '0197a3c0-0000-7000-8000-0000000f6935';
    private const string OMARS_OTHER_TICKET_DRAFT = '0197a3c0-0000-7000-8000-0000000f6936';
    private const string BAKERY_DRAFT = '0197a3c0-0000-7000-8000-0000000f6937';
    private const string REPORT_SHOT = '0197a3c0-0000-7000-8000-0000000f6941';
    private const string LENAS_SHARED_SHOT = '0197a3c0-0000-7000-8000-0000000f6942';
    private const string OMARS_SHOT = '0197a3c0-0000-7000-8000-0000000f6943';
    private const string MIAS_SHOT = '0197a3c0-0000-7000-8000-0000000f6944';
    private const string MIAS_DELETED_SHOT = '0197a3c0-0000-7000-8000-0000000f6945';
    private const string OTHER_TICKET_SHOT = '0197a3c0-0000-7000-8000-0000000f6946';
    private const string BAKERY_SHOT = '0197a3c0-0000-7000-8000-0000000f6947';
    private const string UNKNOWN_SHOT = '0197a3c0-0000-7000-8000-0000000f6949';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f6951';

    private const string BEFORE_ANY_ROW = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';
    private const string DELETED_AT = '2027-03-17 09:00:00+00';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        $newerRounds = [];
        for ($number = 2; $number <= RoundQueries::MAX_NUMBERED_ROUNDS + 2; ++$number) {
            $newerRounds[] = [\sprintf('0197a3c0-0000-7000-8000-0000000f6a%02x', $number), self::TICKET, $number, 'review', self::MIA, self::SHARED_AT, 'open', null];
        }
        self::addRows(self::ACME_FOODS, [
            [self::REPORT, self::TICKET, 0, 'report', self::MIA, self::SHARED_AT, 'open', null],
            [self::LENAS_SHARED_DRAFT, self::TICKET, 1, 'draft', self::LENA, self::SHARED_AT, 'open', null],
            ...$newerRounds,
            [self::OMARS_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open', null],
            [self::MIAS_DRAFT, self::TICKET, null, 'draft', self::MIA, null, 'open', null],
            [self::MIAS_DELETED_DRAFT, self::TICKET, null, 'draft', self::MIA, null, 'closed', self::DELETED_AT],
            [self::OMARS_OTHER_TICKET_DRAFT, self::OTHER_TICKET, null, 'draft', self::OMAR, null, 'open', null],
        ], [
            [self::REPORT_SHOT, self::REPORT],
            [self::LENAS_SHARED_SHOT, self::LENAS_SHARED_DRAFT],
            [self::OMARS_SHOT, self::OMARS_DRAFT],
            [self::MIAS_SHOT, self::MIAS_DRAFT],
            [self::MIAS_DELETED_SHOT, self::MIAS_DELETED_DRAFT],
            [self::OTHER_TICKET_SHOT, self::OMARS_OTHER_TICKET_DRAFT],
        ]);
        // rvw_round.ticket_id has no foreign key, so Beta Bakery may hold a round with Acme's ticket id.
        self::addRows(self::BETA_BAKERY, [
            [self::BAKERY_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open', null],
        ], [
            [self::BAKERY_SHOT, self::BAKERY_DRAFT],
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
    public function theOwnersUnsharedDraftIsFoundByItsPictureInAnyCase(): void
    {
        $expected = new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::OMARS_DRAFT, new TicketId(self::TICKET), new UserId(self::OMAR), true, false);

        self::assertEquals($expected, $this->draftScreenshotAs(self::ACME_FOODS, self::TICKET, self::OMARS_SHOT));
        self::assertEquals($expected, $this->draftScreenshotAs(self::ACME_FOODS, self::TICKET, strtoupper(self::OMARS_SHOT)));
    }

    /**
     * forTicket shows the newest MAX_NUMBERED_ROUNDS rounds only; the lookup by picture has no such cap.
     */
    #[Test]
    public function aSharedDraftOlderThanTheNewestRoundsIsFoundAsShared(): void
    {
        self::assertEquals(
            new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::LENAS_SHARED_DRAFT, new TicketId(self::TICKET), new UserId(self::LENA), false, false),
            $this->draftScreenshotAs(self::ACME_FOODS, self::TICKET, self::LENAS_SHARED_SHOT),
        );
    }

    /**
     * The query makes no visibility check: another person's draft and a deleted one come back with their owner and
     * state, for RoundVoter to deny.
     */
    #[Test]
    public function anotherPersonsDraftAndADeletedOneComeBackAsTheyAreForTheVoter(): void
    {
        self::assertEquals(
            new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::MIAS_DRAFT, new TicketId(self::TICKET), new UserId(self::MIA), true, false),
            $this->draftScreenshotAs(self::ACME_FOODS, self::TICKET, self::MIAS_SHOT),
        );
        self::assertEquals(
            new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::MIAS_DELETED_DRAFT, new TicketId(self::TICKET), new UserId(self::MIA), true, true),
            $this->draftScreenshotAs(self::ACME_FOODS, self::TICKET, self::MIAS_DELETED_SHOT),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function picturesOfNoDraftOfTheTicket(): iterable
    {
        yield 'a picture of the report' => [self::TICKET, self::REPORT_SHOT];
        yield 'a draft\'s picture of another ticket' => [self::TICKET, self::OTHER_TICKET_SHOT];
        yield 'a picture asked for on another ticket' => [self::OTHER_TICKET, self::OMARS_SHOT];
        yield 'an unknown picture' => [self::TICKET, self::UNKNOWN_SHOT];
        yield 'another organization\'s picture of the same ticket id' => [self::TICKET, self::BAKERY_SHOT];
    }

    #[Test]
    #[DataProvider('picturesOfNoDraftOfTheTicket')]
    public function aPictureOfNoDraftOfTheTicketIsNotFound(string $ticketId, string $screenshotId): void
    {
        self::assertNull($this->draftScreenshotAs(self::ACME_FOODS, $ticketId, $screenshotId));
    }

    #[Test]
    public function underAnotherOrganizationOnlyItsOwnDraftIsFound(): void
    {
        self::assertNull($this->draftScreenshotAs(self::BETA_BAKERY, self::TICKET, self::OMARS_SHOT));
        self::assertEquals(
            new RoundAccessFacts(new OrganizationId(self::BETA_BAKERY), self::BAKERY_DRAFT, new TicketId(self::TICKET), new UserId(self::OMAR), true, false),
            $this->draftScreenshotAs(self::BETA_BAKERY, self::TICKET, self::BAKERY_SHOT),
        );
    }

    /**
     * draftScreenshotOf's join with its organization conditions taken out, run as pinround_app: row-level security
     * alone hides Beta Bakery's draft on Acme's ticket id from Acme Foods as tenant, and Beta's own tenant gets it.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsDraftOnTheTicketId(): void
    {
        $draftsAs = static fn (string $organizationId): array => self::transactions()->rolledBackAs($organizationId, static fn (): array => [
            self::connection()->fetchOne('SELECT current_user'),
            self::connection()->fetchFirstColumn(
                'SELECT round.id FROM rvw_screenshot AS screenshot JOIN rvw_round AS round ON round.id = screenshot.round_id'
                .' WHERE screenshot.id = ANY(CAST(:screenshots AS uuid[])) AND round.ticket_id = CAST(:ticket AS uuid)'
                ." AND round.kind = 'draft' ORDER BY round.id",
                ['screenshots' => '{'.self::OMARS_SHOT.','.self::BAKERY_SHOT.'}', 'ticket' => self::TICKET],
            ),
        ]);

        self::assertSame(['pinround_app', [self::OMARS_DRAFT]], $draftsAs(self::ACME_FOODS));
        self::assertSame(['pinround_app', [self::BAKERY_DRAFT]], $draftsAs(self::BETA_BAKERY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedScreenshotIds(): iterable
    {
        yield 'not a uuid' => ['not-a-screenshot-id'];
        yield 'an empty text' => [''];
        yield 'a known id with a trailing newline' => [self::OMARS_SHOT."\n"];
        yield 'a known id with a CRLF' => [self::OMARS_SHOT."\r\n"];
    }

    #[Test]
    #[DataProvider('malformedScreenshotIds')]
    public function aMalformedIdIsNotFoundWithoutAQuery(string $screenshotId): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($screenshotId): int {
            $this->debugData()->reset();
            self::assertNull($this->roundQueries()->draftScreenshotOf(new TicketId(self::TICKET), $screenshotId));

            return self::queryCountOf($this->debugData());
        });

        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function theLookupCostsOneQueryWhateverTheNumberOfRounds(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            self::assertNotNull($this->roundQueries()->draftScreenshotOf(new TicketId(self::TICKET), self::LENAS_SHARED_SHOT));

            return self::queryCountOf($this->debugData());
        });

        self::assertSame(1, $queryCount);
    }

    #[Test]
    public function theLookupOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->roundQueries()->draftScreenshotOf(new TicketId(self::TICKET), self::OMARS_SHOT);
            self::fail('A draft was looked up outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    private function draftScreenshotAs(string $organizationId, string $ticketId, string $screenshotId): ?RoundAccessFacts
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundAccessFacts => $this->roundQueries()->draftScreenshotOf(new TicketId($ticketId), $screenshotId));
    }

    /**
     * @param list<array{string, string, ?int, string, string, ?string, string, ?string}> $rounds      id, ticket, number, kind, owner, shared at, state, deleted at
     * @param list<array{string, string}>                                                 $screenshots id, round; each at position 0
     */
    private static function addRows(string $organizationId, array $rounds, array $screenshots): void
    {
        self::transactions()->committedAs($organizationId, static function () use ($organizationId, $rounds, $screenshots): void {
            foreach ($rounds as $round) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '".self::BEFORE_ANY_ROW."', ?)",
                    [$organizationId, ...$round],
                );
            }
            foreach ($screenshots as [$id, $roundId]) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, variant_label, verdict, title, summary, created_at) VALUES (?, ?, ?, ?, 0, NULL, NULL, NULL, NULL, '".self::BEFORE_ANY_ROW."')",
                    [$organizationId, $id, $roundId, self::MEDIA],
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
