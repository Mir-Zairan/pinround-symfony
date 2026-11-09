<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\RuleSet;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Version20261110140000, the backfill that gives organizations created before SetUpOrganizationWorkflow their core
 * steps and default rule set, run as pinround_migrator (as doctrine:migrations:migrate does) inside a transaction that
 * is rolled back. Its step and rule values are hard-coded SQL, so they are compared with CoreStep and RuleSet here.
 * The tenant is set only when a transaction begins, so tests with two organizations commit both organization rows
 * first, each under its own tenant, and run the backfill once per organization.
 */
final class CoreStepBackfillTest extends KernelTestCase
{
    private const string BACKFILL = 'DoctrineMigrations\Version20261110140000';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001aa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001ab1';

    protected function setUp(): void
    {
        $this->removeRows();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function anOrganizationWithoutStepsGetsTheCoreStepsAndTheDefaultRuleSet(): void
    {
        [$stepRows, $ruleSetRows] = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): array {
            $this->insertOrganization($migrator, self::ACME_FOODS, 'acme-foods-backfill');
            $this->runBackfill($migrator);

            return [$this->coreStepRows($migrator), $this->ruleSetRows($migrator)];
        });

        self::assertSame(
            array_map(static fn (CoreStep $coreStep): array => [
                'team_id' => null,
                'origin' => 'core',
                'core_code' => $coreStep->value,
                'name' => $coreStep->stepName(),
                'grp' => $coreStep->group()->value,
                'position' => $coreStep->position(),
                'archived_at' => null,
            ], CoreStep::cases()),
            $stepRows,
        );
        $defaults = RuleSet::defaults();
        self::assertSame(
            [['team_id' => null, 'tester_moves_done' => $defaults->testerMovesDone, 'no_self_review' => $defaults->noSelfReview, 'review_before_test' => $defaults->reviewBeforeTest]],
            $ruleSetRows,
        );
    }

    /** Each organization's rows are written under that organization, so the tenant policy keeps them apart. */
    #[Test]
    public function everyOrganizationGetsItsOwnCoreSteps(): void
    {
        $this->commitAcmeFoodsAndBetaBakery();

        $stepCounts = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $stepCounts[$organizationId] = $this->rolledBackAs($organizationId, function (Connection $migrator) use ($organizationId): array {
                $this->runBackfill($migrator);

                return [
                    $migrator->fetchOne('SELECT count(*) FROM wf_step'),
                    $migrator->fetchOne('SELECT count(*) FROM wf_step WHERE organization_id = ?', [$organizationId]),
                    $migrator->fetchOne('SELECT count(*) FROM wf_rule_set WHERE organization_id = ?', [$organizationId]),
                ];
            });
        }

        self::assertSame([self::ACME_FOODS => [8, 8, 1], self::BETA_BAKERY => [8, 8, 1]], $stepCounts);
    }

    #[Test]
    public function runningTheBackfillTwiceAddsNothing(): void
    {
        [$afterFirstRun, $afterSecondRun] = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): array {
            $this->insertOrganization($migrator, self::ACME_FOODS, 'acme-foods-backfill');
            $this->runBackfill($migrator);
            $afterFirstRun = $this->allWorkflowRows($migrator);
            $this->runBackfill($migrator);

            return [$afterFirstRun, $this->allWorkflowRows($migrator)];
        });

        self::assertCount(8 + 1, $afterFirstRun);
        self::assertSame($afterFirstRun, $afterSecondRun);
    }

    #[Test]
    public function anOrganizationKeepsTheStepsAndRuleSetItAlreadyHas(): void
    {
        [$stepNames, $ruleSetRows] = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): array {
            $this->insertOrganization($migrator, self::ACME_FOODS, 'acme-foods-backfill');
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position)
                 VALUES (?, '0197a3c0-0000-7000-8000-000000001ad1', NULL, 'Peer review', 'check', 'review', 'core', 3)",
                [self::ACME_FOODS],
            );
            $migrator->executeStatement(
                'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, NULL, false, true, false)',
                [self::ACME_FOODS],
            );
            $this->runBackfill($migrator);

            return [
                $migrator->fetchAllKeyValue('SELECT core_code, name FROM wf_step ORDER BY position'),
                $this->ruleSetRows($migrator),
            ];
        });

        self::assertSame(
            ['backlog' => 'Backlog', 'todo' => 'To do', 'progress' => 'In progress', 'review' => 'Peer review', 'test' => 'Testing', 'input' => 'Needs input', 'hold' => 'On hold', 'done' => 'Done'],
            $stepNames,
        );
        self::assertSame([['team_id' => null, 'tester_moves_done' => false, 'no_self_review' => true, 'review_before_test' => false]], $ruleSetRows);
    }

    /**
     * The backfill lifts FORCE on org_organization to list the organizations; it must put it and the tenant back.
     * The backfill goes in id order and Beta Bakery's id is above Acme Foods', so a tenant left unrestored is not Acme Foods.
     */
    #[Test]
    public function theBackfillLeavesRowLevelSecurityForcedAndTheTenantAsItWas(): void
    {
        $this->commitAcmeFoodsAndBetaBakery();

        [$forcedTables, $tenant] = $this->rolledBackAs(self::ACME_FOODS, function (Connection $migrator): array {
            $this->runBackfill($migrator);

            return [
                $migrator->fetchFirstColumn(
                    "SELECT relname FROM pg_class WHERE oid IN ('public.org_organization'::regclass, 'public.wf_step'::regclass, 'public.wf_rule_set'::regclass)
                     AND relrowsecurity AND relforcerowsecurity",
                ),
                $migrator->fetchOne("SELECT current_setting('app.org_id', true)"),
            ];
        });

        self::assertEqualsCanonicalizing(['org_organization', 'wf_step', 'wf_rule_set'], $forcedTables);
        self::assertSame(self::ACME_FOODS, $tenant);
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function rolledBackAs(string $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $migrator = $this->migrator();
        $tenantContext->set(new OrganizationId($organizationId));
        $migrator->beginTransaction();
        try {
            return $work($migrator);
        } finally {
            $migrator->rollBack();
            $tenantContext->clear();
        }
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function commitAcmeFoodsAndBetaBakery(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ([self::ACME_FOODS => 'acme-foods-backfill', self::BETA_BAKERY => 'beta-bakery-backfill'] as $organizationId => $slug) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(fn (Connection $migrator) => $this->insertOrganization($migrator, $organizationId, $slug));
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function removeRows(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        new WorkflowRows($this->migrator(), $tenantContext)->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new OrganizationRows($this->migrator(), $tenantContext)->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function insertOrganization(Connection $migrator, string $organizationId, string $slug): void
    {
        $migrator->executeStatement(
            "INSERT INTO org_organization (organization_id, name, slug, status, created_at) VALUES (?, ?, ?, 'active', '2026-11-10 09:00:00+00')",
            [$organizationId, $slug, $slug],
        );
    }

    /** Loads the migration as doctrine:migrations:migrate does and runs its SQL on the given connection. */
    private function runBackfill(Connection $migrator): void
    {
        $configuration = new Configuration();
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::getContainer()->getParameter('kernel.project_dir').'/migrations');
        $migrations = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($migrator));
        $backfill = $migrations->getMigrationRepository()->getMigration(new Version(self::BACKFILL))->getMigration();
        $backfill->up($migrator->createSchemaManager()->introspectSchema());

        $statements = $backfill->getSql();
        self::assertCount(1, $statements);
        foreach ($statements as $statement) {
            $migrator->executeStatement($statement->getStatement());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coreStepRows(Connection $migrator): array
    {
        return $migrator->fetchAllAssociative(
            'SELECT team_id, origin, core_code, name, grp, position, archived_at FROM wf_step WHERE organization_id = ? ORDER BY position',
            [self::ACME_FOODS],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ruleSetRows(Connection $migrator): array
    {
        return $migrator->fetchAllAssociative(
            'SELECT team_id, tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE organization_id = ?',
            [self::ACME_FOODS],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allWorkflowRows(Connection $migrator): array
    {
        return [
            ...$migrator->fetchAllAssociative('SELECT * FROM wf_step WHERE organization_id = ? ORDER BY id', [self::ACME_FOODS]),
            ...$this->ruleSetRows($migrator),
        ];
    }
}
