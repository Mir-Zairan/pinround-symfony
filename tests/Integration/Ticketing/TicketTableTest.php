<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tkt_ticket and tkt_summary tables as Version20261111120000 creates them, with the search column of
 * Version20261112100000, the question pause of Version20261115100000 and the AI summary label of Version20261117160000:
 * their indexes, row-level security, the checks
 * the database keeps on its own and the foreign keys that tie summaries and turn rows to their ticket.
 */
final class TicketTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000032a1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000032d1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000032e1';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000032e2';
    private const string SUMMARY = '0197a3c0-0000-7000-8000-0000000032e9';
    private const string EARLIER_SUMMARY = '0197a3c0-0000-7000-8000-0000000032ea';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-0000000032f9';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000032f1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000032f2';
    private const string CHECK_VIOLATION = '23514';
    private const array AI_LABEL = ['author_id' => self::OMAR, 'model' => 'claude-sonnet', 'prompt_version' => 'summary.v1'];
    private const array LAPTOP_LABEL = ['author_id' => self::OMAR, 'model' => 'qwen3:4b', 'prompt_version' => 'summary.v1', 'chosen_by_id' => self::LENA];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->rows()->addProjectOf(self::ACME_FOODS, self::SHOP_PROJECT);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    private function rows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    #[Test]
    public function everyIndexStartsWithTheOrganizationAndTheListsSortByRankThenNewest(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE INDEX tkt_ticket_search_idx ON public.tkt_ticket USING gin (organization_id, search)',
                'CREATE INDEX tkt_ticket_title_trgm_idx ON public.tkt_ticket USING gin (organization_id, title gin_trgm_ops)',
                'CREATE UNIQUE INDEX tkt_ticket_pkey ON public.tkt_ticket USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX tkt_ticket_organization_id_project_id_number_key ON public.tkt_ticket USING btree (organization_id, project_id, number)',
                'CREATE UNIQUE INDEX tkt_ticket_organization_id_key_key ON public.tkt_ticket USING btree (organization_id, key)',
                'CREATE INDEX tkt_ticket_project_id_step_id_priority_rank_updated_at_id_idx ON public.tkt_ticket USING btree (organization_id, project_id, step_id, priority_rank, updated_at DESC, id DESC)',
                'CREATE INDEX tkt_ticket_step_id_priority_rank_updated_at_id_idx ON public.tkt_ticket USING btree (organization_id, step_id, priority_rank, updated_at DESC, id DESC)',
                'CREATE INDEX tkt_ticket_reporter_id_created_at_id_idx ON public.tkt_ticket USING btree (organization_id, reporter_id, created_at DESC, id DESC)',
                'CREATE INDEX tkt_ticket_assignee_id_idx ON public.tkt_ticket USING btree (organization_id, assignee_id) WHERE (closed_at IS NULL)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'tkt_ticket'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX tkt_summary_pkey ON public.tkt_summary USING btree (organization_id, id)',
                'CREATE INDEX tkt_summary_ticket_id_created_at_idx ON public.tkt_summary USING btree (organization_id, ticket_id, created_at DESC)',
                'CREATE UNIQUE INDEX tkt_summary_ticket_id_current_key ON public.tkt_summary USING btree (organization_id, ticket_id) WHERE is_current',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'tkt_summary'"),
        );
    }

    #[Test]
    public function theSearchColumnIsGeneratedFromKeyAndTitleAboveDescription(): void
    {
        self::assertSame(
            [
                'type' => 'tsvector',
                'generated' => 's',
                'expression' => "((setweight(to_tsvector('simple'::regconfig, tkt_unaccent(key)), 'A'::\"char\")"
                    ." || setweight(to_tsvector('simple'::regconfig, tkt_unaccent(title)), 'A'::\"char\"))"
                    ." || setweight(to_tsvector('simple'::regconfig, tkt_unaccent(description)), 'B'::\"char\"))",
            ],
            $this->connection()->fetchAssociative(
                "SELECT format_type(a.atttypid, a.atttypmod) AS type, a.attgenerated::text AS generated, pg_get_expr(d.adbin, d.adrelid) AS expression
                 FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE a.attrelid = 'public.tkt_ticket'::regclass AND a.attname = 'search'",
            ),
        );
    }

    /**
     * A generated column may only call IMMUTABLE functions, and unaccent() is STABLE because it finds its dictionary
     * through search_path; tkt_unaccent names the dictionary and fixes search_path, so its answer depends on the text alone.
     */
    #[Test]
    public function theUnaccentWrapperIsImmutableWithAFixedSearchPathAndOwnedByTheMigrator(): void
    {
        self::assertSame(
            ['owner' => 'pinround_migrator', 'volatility' => 'i', 'is_security_definer' => false, 'config' => '{"search_path=pg_catalog, pg_temp"}'],
            $this->connection()->fetchAssociative(
                "SELECT proowner::regrole::text AS owner, provolatile::text AS volatility, prosecdef AS is_security_definer, proconfig::text AS config
                 FROM pg_proc WHERE oid = 'public.tkt_unaccent(text)'::regprocedure",
            ),
        );
        // Both roles that search call it; like unaccent() itself it keeps the default EXECUTE for PUBLIC.
        self::assertSame(
            [true, true],
            [
                $this->connection()->fetchOne("SELECT has_function_privilege('pinround_app', 'public.tkt_unaccent(text)', 'EXECUTE')"),
                $this->connection()->fetchOne("SELECT has_function_privilege('pinround_readonly', 'public.tkt_unaccent(text)', 'EXECUTE')"),
            ],
        );
    }

    /**
     * Written as the app, which must be able to compute the column: key and title weigh A, description B, accents go.
     */
    #[Test]
    public function aTicketIsFoundByItsWordsWithoutAccents(): void
    {
        $search = $this->rolledBackAsTheApp(function (Connection $connection): mixed {
            $this->insertTicket($connection, ['title' => 'Café menu is hidden', 'description' => 'Señor García saw it.']);

            return $connection->fetchAssociative(
                "SELECT search::text AS terms,
                        search @@ websearch_to_tsquery('simple', public.tkt_unaccent('cafe GARCIA shop-12')) AS is_found,
                        search @@ websearch_to_tsquery('simple', public.tkt_unaccent('cafe pizza')) AS is_found_by_another_word
                 FROM tkt_ticket WHERE id = ?",
                [self::TICKET],
            );
        });

        self::assertSame(
            [
                // The simple parser reads SHOP-12 as the word shop and the signed number -12.
                'terms' => "'-12':2A 'cafe':3A 'garcia':8B 'hidden':6A 'is':5A 'it':10B 'menu':4A 'saw':9B 'senor':7B 'shop':1A",
                'is_found' => true,
                'is_found_by_another_word' => false,
            ],
            $search,
        );
    }

    #[Test]
    public function rowLevelSecurityIsOnAndForcedOnBothTables(): void
    {
        self::assertSame(
            [
                ['relname' => 'tkt_summary', 'relrowsecurity' => true, 'relforcerowsecurity' => true],
                ['relname' => 'tkt_ticket', 'relrowsecurity' => true, 'relforcerowsecurity' => true],
            ],
            $this->connection()->fetchAllAssociative(
                "SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid IN ('public.tkt_ticket'::regclass, 'public.tkt_summary'::regclass) ORDER BY relname",
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function ticketsTheDatabaseRefuses(): iterable
    {
        yield 'an unknown type' => [['type' => 'epic'], 'tkt_ticket_type_check'];
        yield 'an unknown priority' => [['priority' => 'urgent', 'priority_rank' => '0'], 'tkt_ticket_priority_check'];
        yield 'a rank that is not the priority\'s' => [['priority' => 'blocker', 'priority_rank' => '2'], 'tkt_ticket_priority_rank_check'];
        yield 'a rank past minor' => [['priority' => 'minor', 'priority_rank' => '5'], 'tkt_ticket_priority_rank_check'];
        yield 'an unknown visibility' => [['visibility' => 'private'], 'tkt_ticket_visibility_check'];
    }

    /**
     * @param array<string, string> $change
     */
    #[Test]
    #[DataProvider('ticketsTheDatabaseRefuses')]
    public function theDatabaseRefusesABadTypePriorityRankOrVisibility(array $change, string $violatedCheck): void
    {
        try {
            $this->rolledBackAsTheApp(fn (Connection $connection): int|string => $this->insertTicket($connection, $change));
            self::fail('The database accepted the ticket.');
        } catch (DriverException $refusal) {
            // DBAL has no own exception for a check violation; PostgreSQL names the check only in the message.
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), $violatedCheck)]);
        }
    }

    #[Test]
    public function theDatabaseAcceptsEveryPriorityWithItsRank(): void
    {
        $inserted = $this->rolledBackAsTheApp(function (Connection $connection): array {
            $rows = [];
            foreach (['blocker' => 0, 'critical' => 1, 'major' => 2, 'normal' => 3, 'minor' => 4] as $priority => $rank) {
                $rows[] = $this->insertTicket($connection, [
                    'id' => \sprintf('0197a3c0-0000-7000-8000-0000000032c%d', $rank),
                    'number' => (string) ($rank + 1),
                    'key' => 'SHOP-'.($rank + 1),
                    'priority' => $priority,
                    'priority_rank' => (string) $rank,
                ]);
            }

            return $rows;
        });

        self::assertSame([1, 1, 1, 1, 1], $inserted);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function halfSetQuestionPauses(): iterable
    {
        yield 'an asked person without an asker' => [['asked_user_id' => self::LENA]];
        yield 'an asker without an asked person' => [['asker_id' => self::LENA]];
    }

    /**
     * As the migrator, since a hand fix or a DBAL write outside the ticket would come from there.
     *
     * @param array<string, string> $change
     */
    #[Test]
    #[DataProvider('halfSetQuestionPauses')]
    public function theDatabaseRefusesAHalfSetQuestionPause(array $change): void
    {
        try {
            $this->rolledBackAsTheMigrator(fn (Connection $migrator): int|string => $this->insertTicket($migrator, $change));
            self::fail('The database accepted the ticket.');
        } catch (DriverException $refusal) {
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), 'tkt_ticket_question_pause_check')]);
        }
    }

    #[Test]
    public function theDatabaseAcceptsAQuestionPauseWithBothPeople(): void
    {
        $pause = $this->rolledBackAsTheMigrator(function (Connection $migrator): array|false {
            $this->insertTicket($migrator, ['asked_user_id' => self::LENA, 'asker_id' => self::OMAR]);

            return $migrator->fetchAssociative('SELECT asked_user_id, asker_id FROM tkt_ticket WHERE id = ?', [self::TICKET]);
        });

        self::assertSame(['asked_user_id' => self::LENA, 'asker_id' => self::OMAR], $pause);
    }

    #[Test]
    public function theDatabaseRefusesASummaryOfAnUnknownSource(): void
    {
        try {
            $this->rolledBackAsTheApp(function (Connection $connection): void {
                $this->insertTicket($connection, []);
                $this->insertSummary($connection, self::TICKET, 'guess');
            });
            self::fail('The database accepted the summary.');
        } catch (DriverException $refusal) {
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), 'tkt_summary_source_check')]);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function labelsOfAnAiSummary(): iterable
    {
        yield 'without its requester' => ['author_id'];
        yield 'without its model' => ['model'];
        yield 'without its prompt version' => ['prompt_version'];
    }

    #[Test]
    #[DataProvider('labelsOfAnAiSummary')]
    public function theDatabaseRefusesAnAiSummaryWithoutItsLabel(string $missingLabel): void
    {
        try {
            $this->rolledBackAsTheApp(function (Connection $connection) use ($missingLabel): void {
                $this->insertTicket($connection, []);
                $this->insertSummary($connection, self::TICKET, 'ai', [...self::AI_LABEL, $missingLabel => null]);
            });
            self::fail('The database accepted the unlabelled AI summary.');
        } catch (DriverException $refusal) {
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), 'tkt_summary_ai_label_check')]);
        }
    }

    #[Test]
    public function theDatabaseAcceptsALabelledAiSummaryAndAnUnlabelledHumanOne(): void
    {
        $inserted = $this->rolledBackAsTheApp(function (Connection $connection): array {
            $this->insertTicket($connection, []);
            $this->insertSummary($connection, self::TICKET, 'ai', self::AI_LABEL);
            $this->insertSummary($connection, self::TICKET, 'human', ['id' => self::EARLIER_SUMMARY, 'is_current' => 'false']);

            return $connection->fetchAllAssociative('SELECT source, author_id, model, prompt_version FROM tkt_summary WHERE ticket_id = ? ORDER BY source', [self::TICKET]);
        });

        self::assertSame([
            ['source' => 'ai', 'author_id' => self::OMAR, 'model' => 'claude-sonnet', 'prompt_version' => 'summary.v1'],
            ['source' => 'human', 'author_id' => null, 'model' => null, 'prompt_version' => null],
        ], $inserted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function labelsOfALaptopSummary(): iterable
    {
        yield 'without its poster' => ['author_id'];
        yield 'without its model' => ['model'];
        yield 'without its prompt version' => ['prompt_version'];
        yield 'without who chose it' => ['chosen_by_id'];
    }

    #[Test]
    #[DataProvider('labelsOfALaptopSummary')]
    public function theDatabaseRefusesALaptopSummaryWithoutItsLabel(string $missingLabel): void
    {
        try {
            $this->rolledBackAsTheApp(function (Connection $connection) use ($missingLabel): void {
                $this->insertTicket($connection, []);
                $this->insertSummary($connection, self::TICKET, 'laptop', [...self::LAPTOP_LABEL, $missingLabel => null]);
            });
            self::fail('The database accepted the unlabelled laptop summary.');
        } catch (DriverException $refusal) {
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), 'tkt_summary_laptop_label_check')]);
        }
    }

    #[Test]
    public function theDatabaseAcceptsALabelledLaptopSummary(): void
    {
        $inserted = $this->rolledBackAsTheApp(function (Connection $connection): array {
            $this->insertTicket($connection, []);
            $this->insertSummary($connection, self::TICKET, 'laptop', self::LAPTOP_LABEL);

            return $connection->fetchAllAssociative('SELECT source, author_id, model, prompt_version, chosen_by_id FROM tkt_summary WHERE ticket_id = ?', [self::TICKET]);
        });

        self::assertSame([['source' => 'laptop', 'author_id' => self::OMAR, 'model' => 'qwen3:4b', 'prompt_version' => 'summary.v1', 'chosen_by_id' => self::LENA]], $inserted);
    }

    #[Test]
    public function aTurnRowOrSummaryOfAnUnknownTicketIsRefused(): void
    {
        foreach (['ins_ticket_turn_ticket_fkey' => $this->insertTurn(...), 'tkt_summary_ticket_fkey' => fn (Connection $connection, string $ticketId): int|string => $this->insertSummary($connection, $ticketId, 'human')] as $foreignKey => $insertRow) {
            try {
                $this->rolledBackAsTheApp(static fn (Connection $connection): int|string => $insertRow($connection, self::UNKNOWN_TICKET));
                self::fail(\sprintf('The database accepted a row of an unknown ticket despite %s.', $foreignKey));
            } catch (ForeignKeyConstraintViolationException $refusal) {
                self::assertStringContainsString($foreignKey, $refusal->getMessage());
            }
        }

        self::assertSame([1, 1, 1], $this->rolledBackAsTheApp(fn (Connection $connection): array => [
            $this->insertTicket($connection, []),
            $this->insertTurn($connection, self::TICKET),
            $this->insertSummary($connection, self::TICKET, 'human'),
        ]));
    }

    /**
     * The retention job removes closed tickets; their turn row and summaries go with them. As the migrator, since the
     * app may not delete tickets.
     */
    #[Test]
    public function removingATicketRemovesItsTurnRowAndSummaries(): void
    {
        $this->rolledBackAsTheMigrator(function (Connection $migrator): void {
            $this->insertTicket($migrator, []);
            $this->insertTurn($migrator, self::TICKET);
            $this->insertSummary($migrator, self::TICKET, 'human');
            $before = [$migrator->fetchOne('SELECT count(*) FROM ins_ticket_turn'), $migrator->fetchOne('SELECT count(*) FROM tkt_summary')];

            $migrator->executeStatement('DELETE FROM tkt_ticket WHERE id = ?', [self::TICKET]);

            self::assertSame([1, 1], $before);
            self::assertSame([0, 0], [$migrator->fetchOne('SELECT count(*) FROM ins_ticket_turn'), $migrator->fetchOne('SELECT count(*) FROM tkt_summary')]);
        });
    }

    /**
     * @param array<string, string> $change the columns that differ from a valid row
     */
    private function insertTicket(Connection $connection, array $change): int|string
    {
        return $connection->insert('tkt_ticket', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::TICKET,
            'project_id' => self::SHOP_PROJECT,
            'number' => '12',
            'key' => 'SHOP-12',
            'title' => 'Checkout button is hidden',
            'description' => '',
            'type' => 'bug',
            'priority' => 'major',
            'priority_rank' => '2',
            'step_id' => self::TO_DO_STEP,
            'reporter_id' => self::LENA,
            'visibility' => 'all',
            'components' => '{}',
            'check_reasons' => '{}',
            'created_at' => '2027-03-15 10:00:00+00',
            'updated_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]);
    }

    private function insertTurn(Connection $connection, string $ticketId): int|string
    {
        return $connection->insert('ins_ticket_turn', [
            'organization_id' => self::ACME_FOODS,
            'ticket_id' => $ticketId,
            'project_id' => self::SHOP_PROJECT,
            'grp' => 'todo',
            'turn_user_id' => self::LENA,
            'kind' => 'build',
            'base_score' => '18',
            'waiting_since' => '2027-03-15 10:00:00+00',
            'visibility' => 'all',
            'reporter_id' => self::LENA,
        ]);
    }

    /**
     * @param array<string, ?string> $change the columns that differ from a valid human summary
     */
    private function insertSummary(Connection $connection, string $ticketId, string $source, array $change = []): int|string
    {
        return $connection->insert('tkt_summary', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::SUMMARY,
            'ticket_id' => $ticketId,
            'text' => 'The checkout button is hidden on small screens.',
            'source' => $source,
            'is_current' => 'true',
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]);
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function rolledBackAsTheApp(\Closure $work): mixed
    {
        $connection = $this->connection();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $connection->beginTransaction();
        try {
            return $work($connection);
        } finally {
            $connection->rollBack();
            $tenantContext->clear();
        }
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function rolledBackAsTheMigrator(\Closure $work): mixed
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $migrator->beginTransaction();
        try {
            return $work($migrator);
        } finally {
            $migrator->rollBack();
            $tenantContext->clear();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
