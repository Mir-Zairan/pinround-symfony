<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\OpenDraftQueries;
use App\Review\Api\RoundAccessFacts;
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
 * DbalRoundQueries::openDraftOf against the real database on rows committed once per class. On the ticket Omar has a
 * shared draft as Round 1, a deleted draft and, inserted last, his open unshared draft; Mia has an open unshared draft
 * and Lena a closed one. Omar has an open draft on another ticket too, and Beta Bakery holds Lena's open draft under the
 * same ticket id. Every time is fixed in March 2027.
 */
final class DbalOpenDraftOfTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8e01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8e02';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f8e11';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8e12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8e13';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000f8e14';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f8e21';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f8e22';
    private const string OMARS_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e31';
    private const string OMARS_DELETED_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e32';
    private const string MIAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e33';
    private const string LENAS_CLOSED_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e34';
    private const string OMARS_OTHER_TICKET_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e35';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e36';
    private const string BAKERY_LENAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f8e37';

    private const string BEFORE_ANY_ROW = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';
    private const string DELETED_AT = '2027-03-17 09:00:00+00';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::addRounds(self::ACME_FOODS, [
            [self::OMARS_SHARED_DRAFT, self::TICKET, 1, self::OMAR, self::SHARED_AT, 'open', null],
            [self::OMARS_DELETED_DRAFT, self::TICKET, null, self::OMAR, null, 'closed', self::DELETED_AT],
            [self::MIAS_DRAFT, self::TICKET, null, self::MIA, null, 'open', null],
            [self::LENAS_CLOSED_DRAFT, self::TICKET, null, self::LENA, null, 'closed', null],
            [self::OMARS_OTHER_TICKET_DRAFT, self::OTHER_TICKET, null, self::OMAR, null, 'open', null],
            [self::OMARS_DRAFT, self::TICKET, null, self::OMAR, null, 'open', null],
        ]);
        // rvw_round.ticket_id has no foreign key, so Beta Bakery may hold a round with Acme's ticket id.
        self::addRounds(self::BETA_BAKERY, [
            [self::BAKERY_LENAS_DRAFT, self::TICKET, null, self::LENA, null, 'open', null],
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
     * Not his shared draft, his deleted one or the one on another ticket, though each is his.
     */
    #[Test]
    public function theOwnersOpenUnsharedDraftOfTheTicketIsFound(): void
    {
        self::assertEquals(
            new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::OMARS_DRAFT, new TicketId(self::TICKET), new UserId(self::OMAR), true, false),
            $this->openDraftAs(self::ACME_FOODS, self::TICKET, self::OMAR),
        );
        self::assertSame(self::OMARS_OTHER_TICKET_DRAFT, $this->openDraftAs(self::ACME_FOODS, self::OTHER_TICKET, self::OMAR)?->roundId);
    }

    #[Test]
    public function anotherPersonsDraftIsNeverTheCallersOwn(): void
    {
        self::assertNull($this->openDraftAs(self::ACME_FOODS, self::OTHER_TICKET, self::MIA));
        self::assertNull($this->openDraftAs(self::ACME_FOODS, self::TICKET, self::RAJ));
        self::assertSame(self::MIAS_DRAFT, $this->openDraftAs(self::ACME_FOODS, self::TICKET, self::MIA)?->roundId);
    }

    /**
     * Lena's only draft on the ticket in Acme Foods is closed; her open one is Beta Bakery's.
     */
    #[Test]
    public function aClosedDraftAndAnotherOrganizationsOpenOneAreNotFound(): void
    {
        self::assertNull($this->openDraftAs(self::ACME_FOODS, self::TICKET, self::LENA));
        self::assertNull($this->openDraftAs(self::BETA_BAKERY, self::TICKET, self::OMAR));
        self::assertEquals(
            new RoundAccessFacts(new OrganizationId(self::BETA_BAKERY), self::BAKERY_LENAS_DRAFT, new TicketId(self::TICKET), new UserId(self::LENA), true, false),
            $this->openDraftAs(self::BETA_BAKERY, self::TICKET, self::LENA),
        );
    }

    /**
     * openDraftOf's query with its organization condition taken out, run as pinround_app: row-level security alone
     * hides Beta Bakery's draft on Acme's ticket id from Acme Foods as tenant, and Beta's own tenant gets it.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsDraftOnTheTicketId(): void
    {
        $draftsAs = static fn (string $organizationId): array => self::transactions()->rolledBackAs($organizationId, static fn (): array => [
            self::connection()->fetchOne('SELECT current_user'),
            self::connection()->fetchFirstColumn(
                'SELECT round.id FROM rvw_round AS round WHERE round.ticket_id = CAST(:ticket AS uuid) AND round.owner_id = CAST(:owner AS uuid)'
                ." AND round.kind = 'draft' AND round.shared_at IS NULL AND round.state = 'open' ORDER BY round.id",
                ['ticket' => self::TICKET, 'owner' => self::LENA],
            ),
        ]);

        self::assertSame(['pinround_app', []], $draftsAs(self::ACME_FOODS));
        self::assertSame(['pinround_app', [self::BAKERY_LENAS_DRAFT]], $draftsAs(self::BETA_BAKERY));
    }

    #[Test]
    public function theLookupCostsOneQuery(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            self::assertNotNull($this->openDraftQueries()->openDraftOf(new TicketId(self::TICKET), new UserId(self::OMAR)));

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
            $this->openDraftQueries()->openDraftOf(new TicketId(self::TICKET), new UserId(self::OMAR));
            self::fail('A draft was looked up outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    private function openDraftAs(string $organizationId, string $ticketId, string $ownerId): ?RoundAccessFacts
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundAccessFacts => $this->openDraftQueries()->openDraftOf(new TicketId($ticketId), new UserId($ownerId)));
    }

    /**
     * @param list<array{string, string, ?int, string, ?string, string, ?string}> $rounds id, ticket, number, owner, shared at, state, deleted at; each a draft
     */
    private static function addRounds(string $organizationId, array $rounds): void
    {
        self::transactions()->committedAs($organizationId, static function () use ($organizationId, $rounds): void {
            foreach ($rounds as $round) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at, deleted_at) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, 1, '".self::BEFORE_ANY_ROW."', ?)",
                    [$organizationId, ...$round],
                );
            }
        });
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function openDraftQueries(): OpenDraftQueries
    {
        $openDraftQueries = self::getContainer()->get('test.review_round_queries');
        \assert($openDraftQueries instanceof DbalRoundQueries);

        return $openDraftQueries;
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
