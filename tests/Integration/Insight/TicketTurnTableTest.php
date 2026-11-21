<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ins_ticket_turn table as Version20261110160000 creates it: who may do what, its indexes, row-level security and
 * the checks the database keeps on its own.
 */
final class TicketTurnTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000016a1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000016d1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000016c1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000016e1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000016f1';
    private const string CHECK_VIOLATION = '23514';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * The app also deletes, for a ticket that reaches Done; support reads under the tenant policy.
     */
    #[Test]
    public function theAppMayReadAddChangeAndRemoveRowsAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
                 FROM pg_class c, aclexplode(c.relacl) acl
                 WHERE c.oid = 'public.ins_ticket_turn'::regclass AND acl.grantee <> c.relowner",
            ),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = 'public.ins_ticket_turn'::regclass"));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.ins_ticket_turn'::regclass",
        ));
    }

    #[Test]
    public function theKeyAndTheSixIndexesAllStartWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX ins_ticket_turn_pkey ON public.ins_ticket_turn USING btree (organization_id, ticket_id)',
                'CREATE INDEX ins_ticket_turn_turn_user_id_base_score_idx ON public.ins_ticket_turn USING btree (organization_id, turn_user_id, base_score DESC)',
                'CREATE INDEX ins_ticket_turn_check_user_id_base_score_idx ON public.ins_ticket_turn USING btree (organization_id, check_user_id, base_score DESC) WHERE (check_user_id IS NOT NULL)',
                'CREATE INDEX ins_ticket_turn_team_id_turn_user_id_waiting_since_idx ON public.ins_ticket_turn USING btree (organization_id, team_id, turn_user_id, waiting_since)',
                'CREATE INDEX ins_ticket_turn_project_id_turn_user_id_waiting_since_idx ON public.ins_ticket_turn USING btree (organization_id, project_id, turn_user_id, waiting_since)',
                'CREATE INDEX ins_ticket_turn_team_id_grp_idx ON public.ins_ticket_turn USING btree (organization_id, team_id, grp)',
                'CREATE INDEX ins_ticket_turn_assignee_id_idx ON public.ins_ticket_turn USING btree (organization_id, assignee_id)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'ins_ticket_turn'"),
        );
    }

    /**
     * The first part of Home's "needs you" query (data-model.md, "Query patterns"), as pinround_app with the tenant set
     * and the visibility predicate of a member who is neither admin nor triager. The table is analyzed over 600 turns of
     * 60 people first: with the statistics of a near-empty table, which autovacuum may leave at any point of a run, every
     * index costs about the same and the planner picked the assignee index.
     */
    #[Test]
    public function homesTurnQueryReadsTheTurnUserIndex(): void
    {
        $this->addTurnsOfOtherPeople(600, 60);
        $connection = $this->connection();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $connection->beginTransaction();
        try {
            // 600 rows fit in a few pages, for which a sequential scan is cheaper than any index.
            $connection->executeStatement('SET LOCAL enable_seqscan = off');
            $plan = implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $connection->fetchFirstColumn(\sprintf(
                "EXPLAIN SELECT ticket_id, kind, base_score, waiting_since FROM ins_ticket_turn tt
                 WHERE organization_id = '%1\$s' AND turn_user_id = '%2\$s'
                   AND (tt.visibility = 'all' OR tt.team_id = ANY(ARRAY['%3\$s']::uuid[])
                        OR '%2\$s' IN (tt.reporter_id, tt.assignee_id, tt.pending_assignee_id, tt.reviewer_id, tt.tester_id, tt.waiting_on_user_id))
                 ORDER BY base_score DESC LIMIT 100",
                self::ACME_FOODS,
                self::LENA,
                self::SHOP_TEAM,
            ))));
        } finally {
            $connection->rollBack();
            $tenantContext->clear();
        }

        self::assertStringContainsString('Index Scan using ins_ticket_turn_turn_user_id_base_score_idx on ins_ticket_turn tt', $plan);
    }

    #[Test]
    public function rowLevelSecurityIsOnAndForced(): void
    {
        self::assertSame(
            ['relrowsecurity' => true, 'relforcerowsecurity' => true],
            $this->connection()->fetchAssociative("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'public.ins_ticket_turn'::regclass"),
        );
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function rowsTheDatabaseRefuses(): iterable
    {
        yield 'a person and a label' => [['turn_label' => 'Customer'], 'ins_ticket_turn_turn_check'];
        yield 'neither a person nor a label' => [['turn_user_id' => null], 'ins_ticket_turn_turn_check'];
        yield 'the group done' => [['grp' => 'done'], 'ins_ticket_turn_grp_check'];
        yield 'an unknown kind' => [['kind' => 'wait'], 'ins_ticket_turn_kind_check'];
        yield 'an unknown visibility' => [['visibility' => 'private'], 'ins_ticket_turn_visibility_check'];
    }

    /**
     * @param array<string, string|null> $change
     */
    #[Test]
    #[DataProvider('rowsTheDatabaseRefuses')]
    public function theDatabaseRefusesARowBreakingACheck(array $change, string $violatedCheck): void
    {
        try {
            $this->insertedAsTheApp($change);
            self::fail('The database accepted the row.');
        } catch (DriverException $refusal) {
            // DBAL has no own exception for a check violation; PostgreSQL names the check only in the message.
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), $violatedCheck)]);
        }
    }

    #[Test]
    public function theDatabaseAcceptsAPersonsTurnAndALabelsTurn(): void
    {
        self::assertSame(
            [1, 1],
            [$this->insertedAsTheApp([]), $this->insertedAsTheApp(['turn_user_id' => null, 'turn_label' => 'Customer', 'kind' => 'input', 'grp' => 'wait'])],
        );
    }

    /**
     * @param array<string, string|null> $change the columns that differ from a valid row
     *
     * @return int|string the rows inserted, in a transaction that is rolled back
     */
    private function insertedAsTheApp(array $change): int|string
    {
        $row = [
            'organization_id' => self::ACME_FOODS,
            'ticket_id' => self::TICKET,
            'project_id' => self::SHOP_PROJECT,
            'grp' => 'doing',
            'turn_user_id' => self::LENA,
            'turn_label' => null,
            'kind' => 'build',
            'base_score' => '18',
            'waiting_since' => '2027-03-15 10:00:00+00',
            'visibility' => 'all',
            'reporter_id' => self::LENA,
            ...$change,
        ];
        $connection = $this->connection();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $connection->beginTransaction();
        try {
            return $connection->insert('ins_ticket_turn', $row);
        } finally {
            $connection->rollBack();
            $tenantContext->clear();
        }
    }

    /**
     * Tickets SHOP-(1000 + n) for n from 1 to $tickets, each with a turn row waiting on one of $people people by turns,
     * none of them Lena; then the turn table is analyzed, through the migrator, which owns it. tearDown removes the
     * tickets and so, by ON DELETE CASCADE, their turn rows.
     */
    private function addTurnsOfOtherPeople(int $tickets, int $people): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static function (Connection $migrator) use ($tickets, $people): void {
                $migrator->executeStatement(
                    "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                         step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                     SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8000-00000016' || lpad(to_hex(number), 4, '0') AS uuid), CAST(? AS uuid),
                         1000 + number, 'SHOP-' || (1000 + number), 'Checkout button is hidden', '', 'bug', 'major', 2,
                         CAST('0197a3c0-0000-7000-8000-00000000f0d0' AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                         TIMESTAMPTZ '2027-03-15 10:00:00+00', TIMESTAMPTZ '2027-03-15 10:00:00+00'
                     FROM generate_series(1, ?) AS number",
                    [self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, $tickets],
                );
                $migrator->executeStatement(
                    "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
                     SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8000-00000016' || lpad(to_hex(number), 4, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                         'doing', CAST('0197a3c0-0000-7000-8000-00000017' || lpad(to_hex(number % ?), 4, '0') AS uuid), 'build', 100,
                         TIMESTAMPTZ '2027-02-01 06:00:00+00' + number * INTERVAL '1 minute', 'all', CAST(? AS uuid)
                     FROM generate_series(1, ?) AS number",
                    [self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, $people, self::LENA, $tickets],
                );
            });
        } finally {
            $tenantContext->clear();
        }
        $this->migrator()->executeStatement('ANALYZE ins_ticket_turn');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
