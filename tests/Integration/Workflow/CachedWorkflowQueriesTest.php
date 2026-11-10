<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\StepPolicy;
use App\Organization\Application\ChangeStepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Workflow\Application\FixedStepOccupancy;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Application\AddStep;
use App\Workflow\Application\RemoveStep;
use App\Workflow\Application\SetRule;
use App\Workflow\Infrastructure\Cache\CachedRuleQueries;
use App\Workflow\Infrastructure\Cache\CachedStepQueries;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use App\Workflow\Infrastructure\Messenger\MessengerWorkflowEvents;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Api's StepQueries and RuleQueries as the container wires them (through WorkflowCache) on committed rows, with the
 * real commands, event.bus and transaction middleware. A row written straight into a table publishes no event, so a
 * read that still misses it after such a write was answered from the cache.
 */
final class CachedWorkflowQueriesTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002121';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002122';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000002131';
    private const string LENA = '0197a3c0-0000-7000-8000-000000002141';
    private const string SARA = '0197a3c0-0000-7000-8000-000000002142';
    private const string DESIGN_CHECK = '0197a3c0-0000-7000-8000-000000002151';
    private const string TASTING = '0197a3c0-0000-7000-8000-000000002152';
    private const string PAIRING = '0197a3c0-0000-7000-8000-000000002153';
    private const string LEGAL_CHECK = '0197a3c0-0000-7000-8000-000000002154';

    protected function setUp(): void
    {
        $this->tearDownRows();
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->connection()->executeStatement(
                'INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?)',
                [self::ACME_FOODS, self::SHOP_TEAM, 'SHOP', self::LENA, '2027-03-01 09:00:00+00'],
            );
            $this->insertStep(self::ACME_FOODS, self::DESIGN_CHECK, null, 'Design check');
            $this->insertRuleSet(self::ACME_FOODS, null, [true, false, true]);
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->insertStep(self::BETA_BAKERY, self::TASTING, null, 'Tasting');
            $this->insertRuleSet(self::BETA_BAKERY, null, [false, true, false]);
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->tearDownRows();
        parent::tearDown();
    }

    #[Test]
    public function theApiReadsGoThroughTheCache(): void
    {
        self::assertInstanceOf(CachedStepQueries::class, self::getContainer()->get('test.workflow_step_queries'));
        self::assertInstanceOf(CachedRuleQueries::class, self::getContainer()->get('test.workflow_rule_queries'));
    }

    #[Test]
    public function aReadIsKeptSoARowWrittenWithoutAChangeIsNotSeen(): void
    {
        self::assertSame(['Design check'], $this->stepNames());
        self::assertSame(['Design check'], $this->moveTargetNames());
        self::assertSame([true, false, true], $this->rules());

        $this->writeBehindTheCache(function (): void {
            $this->insertStep(self::ACME_FOODS, self::LEGAL_CHECK, null, 'Legal check');
            $this->connection()->executeStatement('UPDATE wf_rule_set SET no_self_review = true WHERE organization_id = ?', [self::ACME_FOODS]);
        });

        self::assertSame(['Design check'], $this->stepNames());
        self::assertSame(['Design check'], $this->moveTargetNames());
        self::assertSame([true, false, true], $this->rules());
    }

    #[Test]
    public function aCommittedAddStepOfTheCompanyOrTheTeamIsSeenByTheNextRead(): void
    {
        $this->moveTargetNames();

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->addStep()(new OrganizationId(self::ACME_FOODS), null, 'Legal check', 'check'));
        self::assertSame(['Design check', 'Legal check'], $this->moveTargetNames(), 'A company step drops the entries of every team.');

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->addStep()(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'Pairing', 'check'));
        self::assertSame(['Design check', 'Legal check', 'Pairing'], $this->moveTargetNames());
    }

    #[Test]
    public function aCommittedRemoveStepIsSeenByTheNextRead(): void
    {
        $this->writeBehindTheCache(fn () => $this->insertStep(self::ACME_FOODS, self::PAIRING, self::SHOP_TEAM, 'Pairing'));
        self::assertSame(['Design check', 'Pairing'], $this->stepNames());

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->removeStep()(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), self::PAIRING, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        self::assertSame(['Design check'], $this->stepNames());
    }

    #[Test]
    public function aCommittedSetRuleOfTheOrganizationOrTheTeamIsSeenByTheNextRead(): void
    {
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Team);
        self::assertSame([true, false, true], $this->rules());

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->setRule()(new OrganizationId(self::ACME_FOODS), null, 'no_self_review', true));
        self::assertSame([true, true, true], $this->rules());

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->setRule()(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'tester_moves_done', false));
        self::assertSame([false, true, true], $this->rules());
    }

    /**
     * ChangeStepPolicy is Organization's and publishes nothing Workflow listens to: the policy is in the key instead.
     */
    #[Test]
    public function aChangedStepPolicyIsSeenByTheNextReadWithoutAnyInvalidation(): void
    {
        $this->writeBehindTheCache(function (): void {
            $this->insertStep(self::ACME_FOODS, self::PAIRING, self::SHOP_TEAM, 'Pairing');
            $this->insertRuleSet(self::ACME_FOODS, self::SHOP_TEAM, [false, false, false]);
        });
        self::assertSame(['Design check', 'Pairing'], $this->moveTargetNames(), 'No settings row means extend.');
        self::assertSame([true, false, true], $this->rules());

        $this->policies()->set(self::ACME_FOODS, StepPolicy::Locked);
        self::assertSame(['Design check'], $this->moveTargetNames());

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => new ChangeStepPolicy(new DoctrineOrgSettingsStore($this->entityManager()))(
            new OrganizationId(self::ACME_FOODS),
            StepPolicy::Team,
            new UserId(self::SARA),
            new \DateTimeImmutable('2027-03-17 09:00:00 UTC'),
        ));
        self::assertSame([false, false, false], $this->rules());
    }

    #[Test]
    public function aRolledBackChangeIsReadInsideItsTransactionOnlyAndInvalidatesNothing(): void
    {
        $this->moveTargetNames();
        $this->writeBehindTheCache(fn () => $this->insertStep(self::ACME_FOODS, self::PAIRING, self::SHOP_TEAM, 'Pairing'));

        $insideTheTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->addStep()(new OrganizationId(self::ACME_FOODS), null, 'Legal check', 'check');

            return $this->namesOf($this->stepQueries()->moveTargetsFor(self::SHOP_TEAM));
        });

        self::assertSame(['Design check', 'Legal check', 'Pairing'], $insideTheTransaction, 'A change waiting for its commit is read from the rows.');
        self::assertSame(['Design check'], $this->moveTargetNames(), 'The entry from before the change is kept.');
    }

    #[Test]
    public function aSavepointCommitInsideAChangeInvalidatesNothing(): void
    {
        $this->moveTargetNames();
        $this->writeBehindTheCache(fn () => $this->insertStep(self::ACME_FOODS, self::PAIRING, self::SHOP_TEAM, 'Pairing'));

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $this->addStep()(new OrganizationId(self::ACME_FOODS), null, 'Legal check', 'check');
            $this->connection()->beginTransaction();
            $this->connection()->commit();
        });

        self::assertSame(['Design check'], $this->moveTargetNames());
    }

    #[Test]
    public function twoOrganizationsNeverShareAnEntryForTheSameTeamId(): void
    {
        self::assertSame(['Design check'], $this->stepNames(self::ACME_FOODS));
        self::assertSame([true, false, true], $this->rules(self::ACME_FOODS));

        self::assertSame(['Tasting'], $this->stepNames(self::BETA_BAKERY));
        self::assertSame([false, true, false], $this->rules(self::BETA_BAKERY));
    }

    /**
     * The entry is filled from the new rows after the commit, then a row is written behind the cache: only the
     * relayed event's handler can make the next read see it, as when a reader saved old rows after the first
     * invalidation.
     */
    #[Test]
    public function theRelayedEventInvalidatesTheTeamsEntriesASecondTime(): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->addStep()(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'Pairing', 'check'));
        self::assertSame(['Design check', 'Pairing'], $this->stepNames());
        $this->writeBehindTheCache(fn () => $this->insertStep(self::ACME_FOODS, self::LEGAL_CHECK, null, 'Legal check'));
        self::assertSame(['Design check', 'Pairing'], $this->stepNames());

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(['Design check', 'Legal check', 'Pairing'], $this->stepNames());
    }

    #[Test]
    public function theKernelResetBetweenRequestsForgetsTheTransactionsTenant(): void
    {
        $workflowCache = self::getContainer()->get(WorkflowCache::class);
        $workflowCache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $loads = 0;
        $load = static function () use (&$loads): int {
            return ++$loads;
        };
        self::assertSame(1, $workflowCache->remember(new TeamId(self::SHOP_TEAM), 'steps', $load));

        self::getContainer()->get('services_resetter')->reset();

        self::assertSame(2, $workflowCache->remember(new TeamId(self::SHOP_TEAM), 'steps', $load));
        self::assertSame(3, $workflowCache->remember(new TeamId(self::SHOP_TEAM), 'steps', $load), 'With no transaction nothing is kept.');
    }

    /**
     * @return list<string>
     */
    private function stepNames(string $organizationId = self::ACME_FOODS): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->namesOf($this->stepQueries()->stepsFor(new TeamId(self::SHOP_TEAM))));
    }

    /**
     * @return list<string>
     */
    private function moveTargetNames(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->namesOf($this->stepQueries()->moveTargetsFor(self::SHOP_TEAM)));
    }

    /**
     * @return array{bool, bool, bool} tester_moves_done, no_self_review, review_before_test
     */
    private function rules(string $organizationId = self::ACME_FOODS): array
    {
        return $this->transactions()->rolledBackAs($organizationId, function (): array {
            $rules = $this->ruleQueries()->rulesFor(self::SHOP_TEAM);

            return [$rules->testerMovesDone, $rules->noSelfReview, $rules->reviewBeforeTest];
        });
    }

    /**
     * @param list<StepView> $steps
     *
     * @return list<string>
     */
    private function namesOf(array $steps): array
    {
        $names = array_map(static fn (StepView $step): string => $step->name(), $steps);
        sort($names);

        return $names;
    }

    /**
     * Commits $write in Acme Foods with no Workflow command, so no event is published and nothing is invalidated.
     *
     * @param \Closure(): mixed $write
     */
    private function writeBehindTheCache(\Closure $write): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, $write);
    }

    private function insertStep(string $organizationId, string $stepId, ?string $teamId, string $name): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, ?, 'check', NULL, ?, 0)",
            [$organizationId, $stepId, $teamId, $name, null === $teamId ? 'company' : 'team'],
        );
    }

    /**
     * @param array{bool, bool, bool} $rules
     */
    private function insertRuleSet(string $organizationId, ?string $teamId, array $rules): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, ?, ?, ?, ?)',
            [$organizationId, $teamId, ...$rules],
            [2 => ParameterType::BOOLEAN, 3 => ParameterType::BOOLEAN, 4 => ParameterType::BOOLEAN],
        );
    }

    private function stepQueries(): StepQueries
    {
        $stepQueries = self::getContainer()->get('test.workflow_step_queries');
        self::assertInstanceOf(StepQueries::class, $stepQueries);

        return $stepQueries;
    }

    private function ruleQueries(): RuleQueries
    {
        $ruleQueries = self::getContainer()->get('test.workflow_rule_queries');
        self::assertInstanceOf(RuleQueries::class, $ruleQueries);

        return $ruleQueries;
    }

    private function addStep(): AddStep
    {
        return new AddStep(new DoctrineSteps($this->entityManager()), new DbalSettingsQueries($this->connection()), new DbalTeamQueries($this->connection()), $this->events());
    }

    private function removeStep(): RemoveStep
    {
        return new RemoveStep(new DoctrineSteps($this->entityManager()), new FixedStepOccupancy(0), $this->events());
    }

    private function setRule(): SetRule
    {
        return new SetRule(new DbalRuleSets($this->connection()), new DbalSettingsQueries($this->connection()), new DbalTeamQueries($this->connection()), $this->events());
    }

    private function events(): MessengerWorkflowEvents
    {
        return new MessengerWorkflowEvents(self::getContainer()->get('event.bus'), $this->connection(), self::getContainer()->get(WorkflowCache::class));
    }

    private function tearDownRows(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function policies(): StepPolicyRows
    {
        return new StepPolicyRows($this->entityManager(), $this->transactions());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function rows(): WorkflowRows
    {
        return new WorkflowRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
