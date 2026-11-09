<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Domain\Step;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\StepOrigin;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The steps and rule set of one organization are out of reach under another organization as tenant, for reads and
 * writes alike, with the tenant filter on and off.
 */
final class WorkflowIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000015a1';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000015a2';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000015c2';
    private const string ACME_TODO_STEP = '0197a3c0-0000-7000-8000-0000000015d1';
    private const string SMUGGLED_STEP = '0197a3c0-0000-7000-8000-0000000015d2';
    private const string OTHER_TODO_STEP = '0197a3c0-0000-7000-8000-0000000015d3';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            new DoctrineSteps($this->entityManager())->add(
                new Step(new StepId(self::ACME_TODO_STEP), new OrganizationId(self::ACME_FOODS), null, StepOrigin::Core, CoreStep::Todo, 'To do', Group::Todo, 1),
            );
            $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), RuleSet::defaults());
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function anotherOrganizationFindsNeitherTheStepNorTheRuleSet(): void
    {
        $steps = new DoctrineSteps($this->entityManager());
        $ruleSets = $this->ruleSets();

        [$step, $ruleSet] = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, static fn (): array => [
            $steps->byId(new StepId(self::ACME_TODO_STEP)),
            $ruleSets->forOrganization(new OrganizationId(self::ACME_FOODS)),
        ]);
        [$ownStep, $ownRuleSet] = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => [
            $steps->byId(new StepId(self::ACME_TODO_STEP)),
            $ruleSets->forOrganization(new OrganizationId(self::ACME_FOODS)),
        ]);

        self::assertSame([null, null], [$step, $ruleSet]);
        self::assertNotNull($ownStep, 'The step is not found under its own organization either, so the check above proves nothing.');
        self::assertNotNull($ownRuleSet, 'The rule set is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function rowLevelSecurityAloneHidesTheStepFromAnotherOrganizationWithTheTenantFilterOff(): void
    {
        $steps = new DoctrineSteps($this->entityManager());
        $this->entityManager()->clear();
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $step = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, static fn (): ?Step => $steps->byId(new StepId(self::ACME_TODO_STEP)));
        $ownStep = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): ?Step => $steps->byId(new StepId(self::ACME_TODO_STEP)));

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertNull($step);
        self::assertNotNull($ownStep, 'The step is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function anotherOrganizationCannotAddAStepInTheNameOfTheOrganization(): void
    {
        $this->assertRefusedByThePolicyOf('wf_step', \sprintf(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES ('%s', '%s', NULL, 'Smuggled', 'todo', NULL, 'company', 9)",
            self::ACME_FOODS,
            self::SMUGGLED_STEP,
        ));

        self::assertSame([self::ACME_TODO_STEP], $this->asAcmeFoods('SELECT id FROM wf_step'));
    }

    #[Test]
    public function anotherOrganizationCannotSaveARuleSetInTheNameOfTheOrganization(): void
    {
        $ruleSets = $this->ruleSets();
        try {
            $this->transactions()->committedAs(self::OTHER_ORGANIZATION, static fn () => $ruleSets->saveForOrganization(
                new OrganizationId(self::ACME_FOODS),
                new RuleSet(testerMovesDone: false, noSelfReview: false, reviewBeforeTest: false),
            ));
            self::fail('Another organization saved a rule set in the name of Acme Foods.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "wf_rule_set"', $refusal->getMessage());
        }

        self::assertEquals(RuleSet::defaults(), $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): ?RuleSet => $ruleSets->forOrganization(new OrganizationId(self::ACME_FOODS))));
    }

    #[Test]
    public function anotherOrganizationChangesNoStepAndNoRuleSetOfTheOrganization(): void
    {
        $changedRows = [
            $this->committedAsOtherOrganization(\sprintf("UPDATE wf_step SET name = 'Taken', position = 99 WHERE organization_id = '%s'", self::ACME_FOODS)),
            $this->committedAsOtherOrganization(\sprintf("UPDATE wf_rule_set SET tester_moves_done = false, no_self_review = false WHERE organization_id = '%s'", self::ACME_FOODS)),
        ];

        self::assertSame([0, 0], $changedRows);
        self::assertSame([['name' => 'To do', 'position' => 1]], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT name, position FROM wf_step')));
        self::assertEquals(RuleSet::defaults(), $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?RuleSet => $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS))));
    }

    #[Test]
    public function anotherOrganizationCannotMoveItsOwnStepIntoTheOrganization(): void
    {
        $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn () => new DoctrineSteps($this->entityManager())->add(
            new Step(new StepId(self::OTHER_TODO_STEP), new OrganizationId(self::OTHER_ORGANIZATION), null, StepOrigin::Company, null, 'Moved', Group::Todo, 2),
        ));

        $this->assertRefusedByThePolicyOf('wf_step', \sprintf("UPDATE wf_step SET organization_id = '%s' WHERE id = '%s'", self::ACME_FOODS, self::OTHER_TODO_STEP));

        self::assertSame([self::ACME_TODO_STEP], $this->asAcmeFoods('SELECT id FROM wf_step'));
        self::assertSame([self::OTHER_TODO_STEP], $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM wf_step')));
    }

    /**
     * A team row, so the move could not clash with Acme Foods' own organization row in the unique index.
     */
    #[Test]
    public function anotherOrganizationCannotMoveItsOwnRuleSetIntoTheOrganization(): void
    {
        $this->committedAsOtherOrganization(\sprintf(
            "INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES ('%s', '%s', false, false, false)",
            self::OTHER_ORGANIZATION,
            self::OTHER_TEAM,
        ));

        $this->assertRefusedByThePolicyOf('wf_rule_set', \sprintf("UPDATE wf_rule_set SET organization_id = '%s' WHERE team_id = '%s'", self::ACME_FOODS, self::OTHER_TEAM));

        self::assertSame([null], $this->asAcmeFoods('SELECT team_id FROM wf_rule_set'));
        self::assertSame([self::OTHER_TEAM], $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->connection()->fetchFirstColumn('SELECT team_id FROM wf_rule_set')));
    }

    private function assertRefusedByThePolicyOf(string $table, string $sql): void
    {
        try {
            $this->committedAsOtherOrganization($sql);
            self::fail(\sprintf('Another organization wrote a row of Acme Foods into %s.', $table));
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $refusal->getMessage());
        }
    }

    /**
     * Commits, so a write that row-level security fails to stop stays for the follow-up read to find.
     */
    private function committedAsOtherOrganization(string $sql): int
    {
        return (int) $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement($sql));
    }

    /**
     * @return list<mixed>
     */
    private function asAcmeFoods(string $sql): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn($sql));
    }

    private function ruleSets(): DbalRuleSets
    {
        return new DbalRuleSets($this->connection());
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

    private function rows(): WorkflowRows
    {
        return new WorkflowRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
