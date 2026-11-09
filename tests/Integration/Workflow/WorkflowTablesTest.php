<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The wf_step and wf_rule_set tables as Version20261110120000 creates them: who may do what, their indexes,
 * row-level security and the checks the database keeps on its own.
 */
final class WorkflowTablesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000016a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000016c1';
    private const string FIRST_STEP = '0197a3c0-0000-7000-8000-0000000016d1';
    private const string SECOND_STEP = '0197a3c0-0000-7000-8000-0000000016d2';
    private const string CHECK_VIOLATION = '23514';
    private const array TABLES = ['wf_step', 'wf_rule_set'];

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * The app reads, adds and changes steps and rule sets and never removes one; support reads under the tenant policy.
     */
    #[Test]
    public function theAppMayReadAddAndChangeButNotRemoveAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        foreach (self::TABLES as $table) {
            self::assertEqualsCanonicalizing(
                ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
                $connection->fetchFirstColumn(
                    "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
                     FROM pg_class c, aclexplode(c.relacl) acl
                     WHERE c.oid = to_regclass(?) AND acl.grantee <> c.relowner",
                    ['public.'.$table],
                ),
                $table,
            );
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
            self::assertSame([], $connection->fetchFirstColumn(
                'SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = to_regclass(?)',
                ['public.'.$table],
            ), $table);
        }
    }

    /**
     * Core steps and the organization's rule set have no team, so both unique indexes count NULL team ids as equal.
     */
    #[Test]
    public function everyIndexStartsWithTheOrganizationAndNullTeamsCountAsEqual(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX wf_step_pkey ON public.wf_step USING btree (organization_id, id)',
                'CREATE INDEX wf_step_organization_id_team_id_grp_position_idx ON public.wf_step USING btree (organization_id, team_id, grp, "position")',
                'CREATE UNIQUE INDEX wf_step_organization_id_team_id_core_code_key ON public.wf_step USING btree (organization_id, team_id, core_code) NULLS NOT DISTINCT WHERE (core_code IS NOT NULL)',
                'CREATE UNIQUE INDEX wf_rule_set_organization_id_team_id_key ON public.wf_rule_set USING btree (organization_id, team_id) NULLS NOT DISTINCT',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ('wf_step', 'wf_rule_set')"),
        );
    }

    #[Test]
    public function rowLevelSecurityIsOnAndForced(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame(
                ['relrowsecurity' => true, 'relforcerowsecurity' => true],
                $this->connection()->fetchAssociative('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]),
                $table,
            );
        }
    }

    /**
     * @return iterable<string, array{array{?string, string, ?string, string}, string}> [team_id, origin, core_code, grp], check
     */
    public static function stepsTheDatabaseRefuses(): iterable
    {
        yield 'an unknown group' => [[null, 'company', null, 'later'], 'wf_step_grp_check'];
        yield 'an unknown origin' => [[null, 'custom', null, 'todo'], 'wf_step_origin_check'];
        yield 'an unknown core code' => [[null, 'core', 'deploy', 'doing'], 'wf_step_core_code_check'];
        yield 'a core step without a core code' => [[null, 'core', null, 'todo'], 'wf_step_origin_check'];
        yield 'a core step of a team' => [[self::SHOP_TEAM, 'core', 'todo', 'todo'], 'wf_step_origin_check'];
        yield 'a company step with a core code' => [[null, 'company', 'todo', 'todo'], 'wf_step_origin_check'];
        yield 'a company step of a team' => [[self::SHOP_TEAM, 'company', null, 'todo'], 'wf_step_origin_check'];
        yield 'a team step without a team' => [[null, 'team', null, 'todo'], 'wf_step_origin_check'];
        yield 'a team step with a core code' => [[self::SHOP_TEAM, 'team', 'todo', 'todo'], 'wf_step_origin_check'];
    }

    /**
     * @param array{?string, string, ?string, string} $step
     */
    #[Test]
    #[DataProvider('stepsTheDatabaseRefuses')]
    public function theDatabaseRefusesAStepWhoseOriginTeamOrCodesDoNotFit(array $step, string $violatedCheck): void
    {
        try {
            $this->asTheApp(fn (Connection $connection): int|string => $this->insertStep($connection, self::FIRST_STEP, ...$step));
            self::fail('The database accepted the step.');
        } catch (DriverException $refusal) {
            // DBAL has no own exception for a check violation; PostgreSQL names the check only in the message.
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), $violatedCheck)], $refusal->getMessage());
        }
    }

    #[Test]
    public function theDatabaseAcceptsACoreACompanyAndATeamStep(): void
    {
        self::assertSame([1, 1, 1], $this->asTheApp(fn (Connection $connection): array => [
            $this->insertStep($connection, self::FIRST_STEP, null, 'core', 'done', 'done'),
            $this->insertStep($connection, self::SECOND_STEP, null, 'company', null, 'check'),
            $this->insertStep($connection, '0197a3c0-0000-7000-8000-0000000016d3', self::SHOP_TEAM, 'team', null, 'wait'),
        ]));
    }

    /**
     * Steps without a core code are left out of the unique index, so a company may have many.
     */
    #[Test]
    public function manyStepsWithoutACoreCodeMayShareTheOrganizationAndTeam(): void
    {
        self::assertSame([1, 1], $this->asTheApp(fn (Connection $connection): array => [
            $this->insertStep($connection, self::FIRST_STEP, null, 'company', null, 'check'),
            $this->insertStep($connection, self::SECOND_STEP, null, 'company', null, 'check'),
        ]));
    }

    #[Test]
    public function theDatabaseRefusesASecondCoreStepWithTheSameCode(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('wf_step_organization_id_team_id_core_code_key');

        $this->asTheApp(function (Connection $connection): void {
            $this->insertStep($connection, self::FIRST_STEP, null, 'core', 'todo', 'todo');
            $this->insertStep($connection, self::SECOND_STEP, null, 'core', 'todo', 'todo');
        });
    }

    #[Test]
    public function theDatabaseRefusesASecondOrganizationRuleSetButTakesOnePerTeam(): void
    {
        self::assertSame(2, $this->asTheApp(fn (Connection $connection): int => $this->insertRuleSet($connection, null) + $this->insertRuleSet($connection, self::SHOP_TEAM)));

        foreach ([null, self::SHOP_TEAM] as $teamId) {
            try {
                $this->asTheApp(fn (Connection $connection): int => $this->insertRuleSet($connection, $teamId) + $this->insertRuleSet($connection, $teamId));
                self::fail(\sprintf('A second rule set of %s was accepted.', $teamId ?? 'the organization'));
            } catch (UniqueConstraintViolationException $refusal) {
                self::assertStringContainsString('wf_rule_set_organization_id_team_id_key', $refusal->getMessage());
            }
        }
    }

    private function insertStep(Connection $connection, string $id, ?string $teamId, string $origin, ?string $coreCode, string $group): int
    {
        return (int) $connection->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, 'Step', ?, ?, ?, 1)",
            [self::ACME_FOODS, $id, $teamId, $group, $coreCode, $origin],
        );
    }

    private function insertRuleSet(Connection $connection, ?string $teamId): int
    {
        return (int) $connection->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, ?, true, true, true)',
            [self::ACME_FOODS, $teamId],
        );
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T what $work returns, in a transaction of Acme Foods that is rolled back
     */
    private function asTheApp(\Closure $work): mixed
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

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
