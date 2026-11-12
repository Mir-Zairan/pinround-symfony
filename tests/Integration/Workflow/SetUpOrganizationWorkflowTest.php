<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\OrganizationSetupStep;
use App\Organization\Application\CreateOrganization;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\Organization\Infrastructure\Messenger\MessengerOrganizationEvents;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Organization\Application\CallbackOrganizationSetupStep;
use App\Workflow\Application\SetUpOrganizationWorkflow;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\StepId;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A new organization gets its core steps and default rule set in the transaction that creates it, through the
 * CreateOrganization the container wires, on the real tables as pinround_app.
 */
final class SetUpOrganizationWorkflowTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000019a1';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000019b6';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    protected function setUp(): void
    {
        $this->removeRows();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aNewOrganizationGetsTheEightCoreStepsAndTheDefaultRuleSet(): void
    {
        [$stepRows, $removableSteps, $ruleSet] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->createOrganization()(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods-workflow', new UserId(self::SARA), null, new \DateTimeImmutable(self::NOW));
            $this->entityManager()->clear();
            $steps = new DoctrineSteps($this->entityManager());

            return [
                $this->connection()->fetchAllAssociative('SELECT organization_id, team_id, origin, core_code, name, grp, position, archived_at FROM wf_step ORDER BY position'),
                array_filter(
                    $this->connection()->fetchFirstColumn('SELECT id FROM wf_step'),
                    static fn (mixed $id): bool => !\is_string($id) || ($steps->byId(new StepId($id))?->isRemovable() ?? true),
                ),
                new DbalRuleSets($this->connection())->forOrganization(new OrganizationId(self::ACME_FOODS)),
            ];
        });

        self::assertSame(
            [
                self::coreStepRow('backlog', 'Backlog', 'todo', 0),
                self::coreStepRow('todo', 'To do', 'todo', 1),
                self::coreStepRow('progress', 'In progress', 'doing', 2),
                self::coreStepRow('review', 'Code review', 'check', 3),
                self::coreStepRow('test', 'Testing', 'check', 4),
                self::coreStepRow('input', 'Needs input', 'wait', 5),
                self::coreStepRow('hold', 'On hold', 'wait', 6),
                self::coreStepRow('done', 'Done', 'done', 7),
            ],
            $stepRows,
        );
        self::assertSame([], $removableSteps);
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: true, reviewBeforeTest: true), $ruleSet);
    }

    #[Test]
    public function theEightCoreStepsAreSavedInOneFlush(): void
    {
        [$flushes, $stepCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $setUpWorkflow = new SetUpOrganizationWorkflow(new DoctrineSteps($this->entityManager()), new DbalRuleSets($this->connection()));

            return [
                FlushCounter::countDuring($this->entityManager(), static fn () => $setUpWorkflow->setUp(new OrganizationId(self::ACME_FOODS))),
                $this->connection()->fetchOne('SELECT count(*) FROM wf_step'),
            ];
        });

        self::assertSame([1, 8], [$flushes, $stepCount]);
    }

    /**
     * The workflow step runs first and writes its rows; the failing step after it must take them back with the
     * organization, so the caller's rollback leaves nothing.
     */
    #[Test]
    public function aFailingSetupStepRollsBackTheWholeOrganization(): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->createOrganizationWith(
                new SetUpOrganizationWorkflow(new DoctrineSteps($this->entityManager()), new DbalRuleSets($this->connection())),
                new CallbackOrganizationSetupStep(static fn () => throw new \RuntimeException('Setup failed.')),
            )(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods-workflow', new UserId(self::SARA), null, new \DateTimeImmutable(self::NOW)));
            self::fail('The failing setup step was not reported.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Setup failed.', $failure->getMessage());
        }
        self::getContainer()->get('doctrine')->resetManager();

        $tables = ['org_organization', 'org_membership', 'org_settings', 'wf_step', 'wf_rule_set'];
        $rowCounts = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_map(
            fn (string $table): mixed => $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE organization_id = ?', $table), [self::ACME_FOODS]),
            $tables,
        ));

        self::assertSame(array_fill_keys($tables, 0), array_combine($tables, $rowCounts));
    }

    /**
     * @return array<string, mixed>
     */
    private static function coreStepRow(string $coreCode, string $name, string $group, int $position): array
    {
        return ['organization_id' => self::ACME_FOODS, 'team_id' => null, 'origin' => 'core', 'core_code' => $coreCode, 'name' => $name, 'grp' => $group, 'position' => $position, 'archived_at' => null];
    }

    private function createOrganization(): CreateOrganization
    {
        return self::getContainer()->get(CreateOrganization::class);
    }

    private function createOrganizationWith(OrganizationSetupStep ...$setupSteps): CreateOrganization
    {
        return new CreateOrganization(
            new DoctrineOrganizations($this->entityManager()),
            new DoctrineMemberships($this->entityManager()),
            new DoctrineOrgSettingsStore($this->entityManager()),
            new MessengerOrganizationEvents(self::getContainer()->get('event.bus'), $this->connection(), self::getContainer()->get(TeamPeopleCache::class)),
            $setupSteps,
        );
    }

    private function removeRows(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        new WorkflowRows($migrator, $tenantContext)->removeAllOf(self::ACME_FOODS);
        new OrganizationRows($migrator, $tenantContext)->removeAllOf(self::ACME_FOODS);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}
