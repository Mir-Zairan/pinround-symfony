<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Infrastructure\Doctrine\DbalStepQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalStepQueries as pinround_app on committed wf_step rows, in a transaction under a tenant, as inside a request.
 * Positions are chosen so that ordering by position alone, by group code or by insertion gives another order than
 * the list order (Waiting, Checking, Doing, To do, Done).
 */
final class DbalStepQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ba1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001bb1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000001bc1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000001bc2';

    private const string BACKLOG = '0197a3c0-0000-7000-8000-000000001bd1';
    private const string TODO = '0197a3c0-0000-7000-8000-000000001bd2';
    private const string IN_PROGRESS = '0197a3c0-0000-7000-8000-000000001bd3';
    private const string CODE_REVIEW = '0197a3c0-0000-7000-8000-000000001bd4';
    private const string TESTING = '0197a3c0-0000-7000-8000-000000001bd5';
    private const string NEEDS_INPUT = '0197a3c0-0000-7000-8000-000000001bd6';
    private const string ON_HOLD = '0197a3c0-0000-7000-8000-000000001bd7';
    private const string DONE = '0197a3c0-0000-7000-8000-000000001bd8';
    // The same group and position as Code review, with a higher id.
    private const string DESIGN_CHECK = '0197a3c0-0000-7000-8000-000000001bd9';
    private const string SHOP_READY_TO_SHIP = '0197a3c0-0000-7000-8000-000000001bda';
    private const string SHOP_REMOVED_PAIRING = '0197a3c0-0000-7000-8000-000000001bdb';
    private const string REMOVED_TRIAGE = '0197a3c0-0000-7000-8000-000000001bdc';
    private const string MOBILE_DEVICE_CHECK = '0197a3c0-0000-7000-8000-000000001bdd';

    private const string BETA_NEEDS_INPUT = '0197a3c0-0000-7000-8000-000000001be1';
    private const string BETA_TASTING = '0197a3c0-0000-7000-8000-000000001be2';
    private const string UNKNOWN_STEP = '0197a3c0-0000-7000-8000-000000001bff';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->organizationRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addStep(self::ACME_FOODS, self::DONE, null, 'Done', 'done', 'done', 'core', 7);
            $this->addStep(self::ACME_FOODS, self::BACKLOG, null, 'Backlog', 'todo', 'backlog', 'core', 0);
            $this->addStep(self::ACME_FOODS, self::TODO, null, 'To do', 'todo', 'todo', 'core', 1);
            $this->addStep(self::ACME_FOODS, self::IN_PROGRESS, null, 'In progress', 'doing', 'progress', 'core', 2);
            $this->addStep(self::ACME_FOODS, self::DESIGN_CHECK, null, 'Design check', 'check', null, 'company', 3);
            $this->addStep(self::ACME_FOODS, self::CODE_REVIEW, null, 'Code review', 'check', 'review', 'core', 3);
            $this->addStep(self::ACME_FOODS, self::TESTING, null, 'Testing', 'check', 'test', 'core', 4);
            $this->addStep(self::ACME_FOODS, self::NEEDS_INPUT, null, 'Needs input', 'wait', 'input', 'core', 5);
            $this->addStep(self::ACME_FOODS, self::ON_HOLD, null, 'On hold', 'wait', 'hold', 'core', 6);
            $this->addStep(self::ACME_FOODS, self::SHOP_READY_TO_SHIP, self::SHOP_TEAM, 'Ready to ship', 'wait', null, 'team', 0);
            $this->addStep(self::ACME_FOODS, self::SHOP_REMOVED_PAIRING, self::SHOP_TEAM, 'Pairing', 'doing', null, 'team', 1, '2026-11-01 09:00:00+00');
            $this->addStep(self::ACME_FOODS, self::REMOVED_TRIAGE, null, 'Triage', 'todo', null, 'company', 2, '2026-11-01 09:00:00+00');
            $this->addStep(self::ACME_FOODS, self::MOBILE_DEVICE_CHECK, self::MOBILE_TEAM, 'Device check', 'check', null, 'team', 5);
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addStep(self::BETA_BAKERY, self::BETA_TASTING, null, 'Tasting', 'check', null, 'company', 0);
            $this->addStep(self::BETA_BAKERY, self::BETA_NEEDS_INPUT, null, 'Needs input', 'wait', 'input', 'core', 5);
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->organizationRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function stepsForGivesTheCoreCompanyAndOwnTeamStepsInListOrderThenOriginThenPositionThenId(): void
    {
        self::assertSame(
            [
                [self::NEEDS_INPUT, 'Needs input', 'wait', 'Waiting', 5, 'core', 'input'],
                [self::ON_HOLD, 'On hold', 'wait', 'Waiting', 6, 'core', 'hold'],
                [self::SHOP_READY_TO_SHIP, 'Ready to ship', 'wait', 'Waiting', 0, 'team', null],
                [self::CODE_REVIEW, 'Code review', 'check', 'Checking', 3, 'core', 'review'],
                [self::TESTING, 'Testing', 'check', 'Checking', 4, 'core', 'test'],
                [self::DESIGN_CHECK, 'Design check', 'check', 'Checking', 3, 'company', null],
                [self::IN_PROGRESS, 'In progress', 'doing', 'Doing', 2, 'core', 'progress'],
                [self::BACKLOG, 'Backlog', 'todo', 'To do', 0, 'core', 'backlog'],
                [self::TODO, 'To do', 'todo', 'To do', 1, 'core', 'todo'],
                [self::DONE, 'Done', 'done', 'Done', 7, 'core', 'done'],
            ],
            $this->stepsFor(self::ACME_FOODS, self::SHOP_TEAM),
        );
    }

    #[Test]
    public function stepsForLeavesOutAnotherTeamsStepsAndRemovedSteps(): void
    {
        $mobileStepIds = array_column($this->stepsFor(self::ACME_FOODS, self::MOBILE_TEAM), 0);

        self::assertContains(self::MOBILE_DEVICE_CHECK, $mobileStepIds, 'The other team does see its own step.');
        self::assertNotContains(self::SHOP_READY_TO_SHIP, $mobileStepIds);
        self::assertNotContains(self::SHOP_REMOVED_PAIRING, $mobileStepIds);
        self::assertNotContains(self::REMOVED_TRIAGE, $mobileStepIds);
        self::assertCount(10, $mobileStepIds);
    }

    #[Test]
    public function moveTargetsForLeavesOutTheTeamsOwnStepsUnderLockedAndKeepsCoreAndCompanySteps(): void
    {
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Locked);

        $shopTargetIds = $this->moveTargetIds(self::ACME_FOODS, self::SHOP_TEAM);

        self::assertNotContains(self::SHOP_READY_TO_SHIP, $shopTargetIds);
        self::assertSame(
            array_values(array_diff(array_column($this->stepsFor(self::ACME_FOODS, self::SHOP_TEAM), 0), [self::SHOP_READY_TO_SHIP])),
            $shopTargetIds,
        );
        self::assertContains(self::DESIGN_CHECK, $shopTargetIds);
        self::assertNotContains(self::MOBILE_DEVICE_CHECK, $this->moveTargetIds(self::ACME_FOODS, self::MOBILE_TEAM));
        self::assertCount(9, $this->moveTargetIds(self::ACME_FOODS, self::MOBILE_TEAM));
    }

    #[Test]
    public function moveTargetsForEqualsStepsForUnderExtendTeamAndWithNoSettings(): void
    {
        $expectedIds = array_column($this->stepsFor(self::ACME_FOODS, self::SHOP_TEAM), 0);
        self::assertContains(self::SHOP_READY_TO_SHIP, $expectedIds);

        self::assertSame($expectedIds, $this->moveTargetIds(self::ACME_FOODS, self::SHOP_TEAM), 'No settings row means extend.');

        $this->policies()->set(self::ACME_FOODS, StepPolicy::Extend);
        self::assertSame($expectedIds, $this->moveTargetIds(self::ACME_FOODS, self::SHOP_TEAM));

        $this->organizationRows()->removeAllOf(self::ACME_FOODS);
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Team);
        self::assertSame($expectedIds, $this->moveTargetIds(self::ACME_FOODS, self::SHOP_TEAM));
    }

    #[Test]
    public function anotherOrganizationsLockedPolicyDoesNotHideMyTeamSteps(): void
    {
        $this->policies()->set(self::BETA_BAKERY, StepPolicy::Locked);

        self::assertContains(self::SHOP_READY_TO_SHIP, $this->moveTargetIds(self::ACME_FOODS, self::SHOP_TEAM));
    }

    #[Test]
    public function moveTargetsUnderFollowsThePassedPolicyNotTheStoredOne(): void
    {
        $allIds = array_column($this->stepsFor(self::ACME_FOODS, self::SHOP_TEAM), 0);

        $this->policies()->set(self::ACME_FOODS, StepPolicy::Locked);
        self::assertSame($allIds, $this->moveTargetIdsUnder(StepPolicy::Extend), 'Stored locked, passed extend: the team step stays.');

        $this->organizationRows()->removeAllOf(self::ACME_FOODS);
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Extend);
        self::assertSame(
            array_values(array_diff($allIds, [self::SHOP_READY_TO_SHIP])),
            $this->moveTargetIdsUnder(StepPolicy::Locked),
            'Stored extend, passed locked: the team step goes.',
        );
    }

    #[Test]
    public function groupOfGivesTheGroupCodeOfAStepAlsoWhenItWasRemoved(): void
    {
        self::assertSame(
            ['check', 'wait', 'doing', 'todo', null],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->queries()->groupOf(self::CODE_REVIEW),
                $this->queries()->groupOf(self::SHOP_READY_TO_SHIP),
                $this->queries()->groupOf(self::SHOP_REMOVED_PAIRING),
                $this->queries()->groupOf(self::REMOVED_TRIAGE),
                $this->queries()->groupOf(self::UNKNOWN_STEP),
            ]),
        );
    }

    #[Test]
    public function groupOfReadsAStepIdInAnyCase(): void
    {
        self::assertSame('check', $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->queries()->groupOf(strtoupper(self::CODE_REVIEW))));
    }

    #[Test]
    public function groupOfRefusesAnIdThatIsNotAStepIdBeforeAnyQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->queries()->groupOf('step-1'));
    }

    #[Test]
    public function anotherOrganizationSeesOnlyItsOwnSteps(): void
    {
        self::assertSame(
            [
                [self::BETA_NEEDS_INPUT, 'Needs input', 'wait', 'Waiting', 5, 'core', 'input'],
                [self::BETA_TASTING, 'Tasting', 'check', 'Checking', 0, 'company', null],
            ],
            $this->stepsFor(self::BETA_BAKERY, self::SHOP_TEAM),
        );
        self::assertSame(
            [null, 'check'],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => [
                $this->queries()->groupOf(self::CODE_REVIEW),
                $this->queries()->groupOf(self::BETA_TASTING),
            ]),
        );
    }

    /**
     * DbalStepQueries runs plain SQL, which the ORM tenant filter never touches; row-level security alone keeps the
     * organizations apart.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesTheStepsWithTheTenantFilterOff(): void
    {
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $betaStepIds = array_column($this->stepsFor(self::BETA_BAKERY, self::SHOP_TEAM), 0);
        $betaGroupOfAcmeStep = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?string => $this->queries()->groupOf(self::CODE_REVIEW));

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame([self::BETA_NEEDS_INPUT, self::BETA_TASTING], $betaStepIds);
        self::assertNull($betaGroupOfAcmeStep);
    }

    #[Test]
    public function listedStepsGivesEveryStepOfTheOrganizationOfEveryTeamAndRemovedInListOrderThenOriginThenPositionThenId(): void
    {
        self::assertSame(
            [
                [self::NEEDS_INPUT, 'Needs input', 'wait', 'Waiting', 'Someone must answer first', false],
                [self::ON_HOLD, 'On hold', 'wait', 'Waiting', 'Someone must answer first', false],
                [self::SHOP_READY_TO_SHIP, 'Ready to ship', 'wait', 'Waiting', 'Someone must answer first', false],
                [self::CODE_REVIEW, 'Code review', 'check', 'Checking', 'Being checked', false],
                [self::TESTING, 'Testing', 'check', 'Checking', 'Being checked', false],
                [self::DESIGN_CHECK, 'Design check', 'check', 'Checking', 'Being checked', false],
                [self::MOBILE_DEVICE_CHECK, 'Device check', 'check', 'Checking', 'Being checked', false],
                [self::IN_PROGRESS, 'In progress', 'doing', 'Doing', 'Being built', false],
                [self::SHOP_REMOVED_PAIRING, 'Pairing', 'doing', 'Doing', 'Being built', true],
                [self::BACKLOG, 'Backlog', 'todo', 'To do', 'Not started', false],
                [self::TODO, 'To do', 'todo', 'To do', 'Not started', false],
                [self::REMOVED_TRIAGE, 'Triage', 'todo', 'To do', 'Not started', true],
                [self::DONE, 'Done', 'done', 'Done', null, false],
            ],
            $this->listedStepsAs(self::ACME_FOODS, $this->queries()),
        );
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' steps from Beta Bakery's transaction is row-level security alone.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsListedSteps(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);

        $stepsUnderAcmeContext = new OwnConnectionTransactions($this->connection(), $tenantContext)->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            static fn (Connection $connection): array => new DbalStepQueries($connection, new DbalSettingsQueries($connection), $tenantContext)->listedSteps(),
        );

        self::assertSame([], $stepsUnderAcmeContext);
    }

    #[Test]
    public function listedStepsRefusesWithNoTenant(): void
    {
        $this->expectException(\LogicException::class);
        $this->queries()->listedSteps();
    }

    #[Test]
    public function withNoTenantNothingIsFound(): void
    {
        self::assertSame([], $this->queries()->stepsFor(new TeamId(self::SHOP_TEAM)));
        self::assertNull($this->queries()->groupOf(self::CODE_REVIEW));
    }

    /**
     * @return list<string>
     */
    private function moveTargetIds(string $tenantId, string $teamId): array
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): array => array_map(
            static fn (StepView $step): string => $step->id(),
            $this->queries()->moveTargetsFor(new TeamId($teamId)),
        ));
    }

    /**
     * @return list<string>
     */
    private function moveTargetIdsUnder(StepPolicy $policy): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_map(
            static fn (StepView $step): string => $step->id(),
            $this->queries()->moveTargetsUnder(new TeamId(self::SHOP_TEAM), $policy),
        ));
    }

    private function organizationRows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    /**
     * @return list<array{string, string, string, string, int, string, ?string}>
     */
    private function stepsFor(string $tenantId, string $teamId): array
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): array => array_map(
            static fn (StepView $step): array => [$step->id(), $step->name(), $step->groupCode(), $step->groupLabel(), $step->position(), $step->origin(), $step->coreCode()],
            $this->queries()->stepsFor(new TeamId($teamId)),
        ));
    }

    private function addStep(
        string $organizationId,
        string $stepId,
        ?string $teamId,
        string $name,
        string $groupCode,
        ?string $coreCode,
        string $origin,
        int $position,
        ?string $archivedAt = null,
    ): void {
        $this->connection()->executeStatement(
            'INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$organizationId, $stepId, $teamId, $name, $groupCode, $coreCode, $origin, $position, $archivedAt],
        );
    }

    /**
     * @return list<array{string, string, string, string, string|null, bool}>
     */
    private function listedStepsAs(string $tenantId, StepQueries $stepQueries): array
    {
        return $this->transactions()->rolledBackAs($tenantId, static fn (): array => array_map(
            static fn (ListedStepView $step): array => [$step->id, $step->name, $step->groupCode, $step->groupLabel, $step->groupHint, $step->isArchived],
            $stepQueries->listedSteps(),
        ));
    }

    private function queries(): DbalStepQueries
    {
        return new DbalStepQueries($this->connection(), new DbalSettingsQueries($this->connection()), self::getContainer()->get(TenantContext::class));
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

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): WorkflowRows
    {
        return new WorkflowRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
