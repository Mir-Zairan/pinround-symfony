<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Application\OpenSharedRound;
use App\Review\Application\OpenSharedRoundQueries;
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
 * DbalRoundQueries::lowestOpenSharedRoundOf, the round bar's read, against the real database on rows committed once per
 * class. On the ticket: Round 0 and the checklist round, open as they always are; a closed review round 2; Mia's open
 * shared draft 6, inserted first; Omar's open shared draft 4 and Lena's open review round 5; Lena's open unshared draft.
 * Another ticket has an open review round 3, and Beta Bakery an open review round 3 on the same ticket id; both are lower
 * than 4. A quiet ticket holds only closed rounds and an open unshared draft. A ticket's numbers are unique
 * (rvw_round_ticket_id_number_key), so two rounds never tie. Every time is fixed in March 2027.
 */
final class DbalLowestOpenSharedRoundOfTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f9a02';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f9a11';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f9a12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f9a13';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000f9a14';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f9a21';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000f9a22';
    private const string QUIET_TICKET = '0197a3c0-0000-7000-8000-0000000f9a23';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000f9a24';
    private const string HANDED_OFF_TICKET = '0197a3c0-0000-7000-8000-0000000f9a25';
    private const string HANDED_OFF_DRAFT = '0197a3c0-0000-7000-8000-0000000f9a70';

    private const string REPORTED_AT = '2027-03-15 10:20:30+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::addRounds(self::ACME_FOODS, [
            ['0197a3c0-0000-7000-8000-0000000f9a36', self::TICKET, 6, 'draft', self::MIA, self::SHARED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a30', self::TICKET, 0, 'report', self::MIA, self::REPORTED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a31', self::TICKET, null, 'checklist', self::MIA, self::SHARED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a32', self::TICKET, 2, 'review', self::LENA, self::SHARED_AT, 'closed'],
            ['0197a3c0-0000-7000-8000-0000000f9a35', self::TICKET, 5, 'review', self::LENA, self::SHARED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a34', self::TICKET, 4, 'draft', self::OMAR, self::SHARED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a37', self::TICKET, null, 'draft', self::LENA, null, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a40', self::OTHER_TICKET, 3, 'review', self::LENA, self::SHARED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a50', self::QUIET_TICKET, 0, 'report', self::MIA, self::REPORTED_AT, 'open'],
            ['0197a3c0-0000-7000-8000-0000000f9a51', self::QUIET_TICKET, 1, 'review', self::LENA, self::SHARED_AT, 'closed'],
            ['0197a3c0-0000-7000-8000-0000000f9a52', self::QUIET_TICKET, 2, 'draft', self::OMAR, self::SHARED_AT, 'closed'],
            ['0197a3c0-0000-7000-8000-0000000f9a53', self::QUIET_TICKET, null, 'draft', self::OMAR, null, 'open'],
            [self::HANDED_OFF_DRAFT, self::HANDED_OFF_TICKET, 2, 'draft', self::OMAR, self::SHARED_AT, 'open'],
        ]);
        $handedOffDraft = self::HANDED_OFF_DRAFT;
        self::transactions()->committedAs(self::ACME_FOODS, static function () use ($handedOffDraft): void {
            self::connection()->executeStatement("UPDATE rvw_round SET decision = 'shared', decided_at = shared_at WHERE id = ?", [$handedOffDraft]);
        });
        // rvw_round.ticket_id has no foreign key, so Beta Bakery may hold a round with Acme's ticket id.
        self::addRounds(self::BETA_BAKERY, [
            ['0197a3c0-0000-7000-8000-0000000f9a60', self::TICKET, 3, 'review', self::BEN, self::SHARED_AT, 'open'],
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
     * Not Round 0 or the checklist round, open though they are, nor the closed round 2, Lena's unshared draft, the other
     * ticket's round 3 or Beta Bakery's round 3; and draft 4 before review 5 and draft 6, whatever order they were written in.
     */
    #[Test]
    public function theLowestNumberedOpenSharedRoundOfTheTicketIsFound(): void
    {
        self::assertEquals(new OpenSharedRound(4, 'draft', new UserId(self::OMAR), false), $this->lowestOpenSharedRoundAs(self::ACME_FOODS, self::TICKET));
        self::assertEquals(new OpenSharedRound(3, 'review', new UserId(self::LENA), false), $this->lowestOpenSharedRoundAs(self::ACME_FOODS, self::OTHER_TICKET));
    }

    /**
     * Omar shared draft 4 with "Share now", which records no decision, and draft 2 of the other ticket at hand-off.
     */
    #[Test]
    public function theRoundSaysWhetherItsOwnerSharedItInAMove(): void
    {
        self::assertFalse($this->lowestOpenSharedRoundAs(self::ACME_FOODS, self::TICKET)?->isSharedInAMove);
        self::assertEquals(new OpenSharedRound(2, 'draft', new UserId(self::OMAR), true), $this->lowestOpenSharedRoundAs(self::ACME_FOODS, self::HANDED_OFF_TICKET));
    }

    #[Test]
    public function aTicketWithOnlyClosedRoundsAndAnUnsharedDraftHasNone(): void
    {
        self::assertNull($this->lowestOpenSharedRoundAs(self::ACME_FOODS, self::QUIET_TICKET));
        self::assertNull($this->lowestOpenSharedRoundAs(self::ACME_FOODS, self::UNKNOWN_TICKET));
    }

    #[Test]
    public function anotherOrganizationGetsOnlyItsOwnRoundOnTheTicketId(): void
    {
        self::assertEquals(new OpenSharedRound(3, 'review', new UserId(self::BEN), false), $this->lowestOpenSharedRoundAs(self::BETA_BAKERY, self::TICKET));
        self::assertNull($this->lowestOpenSharedRoundAs(self::BETA_BAKERY, self::OTHER_TICKET));
    }

    /**
     * One query on rvw_round alone: no screenshot, pin or ticket table is read.
     */
    #[Test]
    public function theLookupCostsOneQueryOfTheRoundTableAlone(): void
    {
        $sentSql = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->debugData()->reset();
            self::assertNotNull($this->openSharedRoundQueries()->lowestOpenSharedRoundOf(new TicketId(self::TICKET)));

            return self::sqlOf($this->debugData());
        });

        self::assertCount(1, $sentSql);
        self::assertSame(['rvw_round'], self::tablesIn($sentSql[0]));
    }

    #[Test]
    public function theLookupOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->openSharedRoundQueries()->lowestOpenSharedRoundOf(new TicketId(self::TICKET));
            self::fail('A round was looked up outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    private function lowestOpenSharedRoundAs(string $organizationId, string $ticketId): ?OpenSharedRound
    {
        return self::transactions()->rolledBackAs($organizationId, fn (): ?OpenSharedRound => $this->openSharedRoundQueries()->lowestOpenSharedRoundOf(new TicketId($ticketId)));
    }

    /**
     * @return list<string> each table named after FROM or JOIN, once, in the order first named
     */
    private static function tablesIn(string $sql): array
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+(\w+)/', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param list<array{string, string, ?int, string, string, ?string, string}> $rounds id, ticket, number, kind, owner, shared at, state
     */
    private static function addRounds(string $organizationId, array $rounds): void
    {
        self::transactions()->committedAs($organizationId, static function () use ($organizationId, $rounds): void {
            foreach ($rounds as $round) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '2027-03-15 10:00:00+00')",
                    [$organizationId, ...$round],
                );
            }
        });
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function openSharedRoundQueries(): OpenSharedRoundQueries
    {
        $openSharedRoundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($openSharedRoundQueries instanceof OpenSharedRoundQueries);

        return $openSharedRoundQueries;
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
