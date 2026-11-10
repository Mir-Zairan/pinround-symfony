<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Workflow\Application\FixedStepOccupancy;
use App\Tests\Unit\Workflow\Application\RecordingWorkflowEvents;
use App\Workflow\Api\Event\StepRemoved;
use App\Workflow\Api\StepView;
use App\Workflow\Application\CoreStepNotRemovable;
use App\Workflow\Application\RemoveStep;
use App\Workflow\Application\StepHasOpenTickets;
use App\Workflow\Application\UnknownStep;
use App\Workflow\Infrastructure\Doctrine\DbalStepQueries;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RemoveStep on committed wf_step rows as pinround_app, each call in a rolled-back transaction under a tenant.
 */
final class RemoveStepTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001fa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001fb1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000001fc1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000001fc2';
    private const string CODE_REVIEW = '0197a3c0-0000-7000-8000-000000001fd1';
    private const string SHOP_READY_TO_MERGE = '0197a3c0-0000-7000-8000-000000001fd2';
    private const string DESIGN_CHECK = '0197a3c0-0000-7000-8000-000000001fd3';
    private const string MOBILE_DEVICE_CHECK = '0197a3c0-0000-7000-8000-000000001fd4';
    private const string UNKNOWN_STEP = '0197a3c0-0000-7000-8000-000000001fff';
    private const string NOW = '2027-03-15 10:00:00+00';
    private const string LATER = '2027-03-16 08:30:00+00';

    private RecordingWorkflowEvents $events;

    protected function setUp(): void
    {
        $this->events = new RecordingWorkflowEvents();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->insertStep(self::CODE_REVIEW, null, 'Code review', 'review', 'core', 3);
            $this->insertStep(self::DESIGN_CHECK, null, 'Design check', null, 'company', 0);
            $this->insertStep(self::SHOP_READY_TO_MERGE, self::SHOP_TEAM, 'Ready to merge', null, 'team', 0);
            $this->insertStep(self::MOBILE_DEVICE_CHECK, self::MOBILE_TEAM, 'Device check', null, 'team', 0);
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aTeamStepIsArchivedAndLeavesTheTeamsSteps(): void
    {
        $stepOccupancy = new FixedStepOccupancy(0);
        [$stepIds, $archivedAt] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($stepOccupancy): array {
            $this->removeStep($stepOccupancy)(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), self::SHOP_READY_TO_MERGE, new \DateTimeImmutable(self::NOW));

            return [
                array_map(static fn (StepView $step): string => $step->id(), new DbalStepQueries($this->connection(), new DbalSettingsQueries($this->connection()), self::getContainer()->get(TenantContext::class))->stepsFor(new TeamId(self::SHOP_TEAM))),
                $this->archivedAtOf(self::SHOP_READY_TO_MERGE),
            ];
        });

        self::assertSame([self::CODE_REVIEW, self::DESIGN_CHECK], $stepIds);
        self::assertSame('2027-03-15 10:00:00', $archivedAt);
        self::assertSame([self::SHOP_READY_TO_MERGE], $stepOccupancy->askedStepIds);
        self::assertEquals(
            [new StepRemoved(self::ACME_FOODS, self::SHOP_READY_TO_MERGE, self::SHOP_TEAM, new \DateTimeImmutable(self::NOW))],
            $this->events->published,
        );
    }

    #[Test]
    public function aCompanyStepRemovedPublishesStepRemovedWithNoTeam(): void
    {
        $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): void {
            $this->removeStep(new FixedStepOccupancy(0))(new OrganizationId(self::ACME_FOODS), null, self::DESIGN_CHECK, new \DateTimeImmutable(self::NOW));
        });

        self::assertEquals([new StepRemoved(self::ACME_FOODS, self::DESIGN_CHECK, null, new \DateTimeImmutable(self::NOW))], $this->events->published);
    }

    #[Test]
    public function aCompanyStepIsArchived(): void
    {
        $archivedIds = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->removeStep(new FixedStepOccupancy(0))(new OrganizationId(self::ACME_FOODS), null, self::DESIGN_CHECK, new \DateTimeImmutable(self::NOW));

            return $this->connection()->fetchFirstColumn('SELECT id FROM wf_step WHERE archived_at IS NOT NULL');
        });

        self::assertSame([self::DESIGN_CHECK], $archivedIds);
    }

    /**
     * The step is loaded again for the second call, which keeps the first time instead of moving it to the later one.
     */
    #[Test]
    public function removingARemovedStepAgainKeepsItsFirstTime(): void
    {
        [$archivedAt, $archivedCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $removeStep = $this->removeStep(new FixedStepOccupancy(0));
            $removeStep(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), self::SHOP_READY_TO_MERGE, new \DateTimeImmutable(self::NOW));
            $this->entityManager()->clear();
            $removeStep(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), self::SHOP_READY_TO_MERGE, new \DateTimeImmutable(self::LATER));

            return [
                $this->archivedAtOf(self::SHOP_READY_TO_MERGE),
                $this->connection()->fetchOne('SELECT count(*) FROM wf_step WHERE archived_at IS NOT NULL'),
            ];
        });

        self::assertSame('2027-03-15 10:00:00', $archivedAt);
        self::assertSame(1, $archivedCount);
        self::assertEquals(
            [new StepRemoved(self::ACME_FOODS, self::SHOP_READY_TO_MERGE, self::SHOP_TEAM, new \DateTimeImmutable(self::NOW))],
            $this->events->published,
            'The second removal publishes nothing.',
        );
    }

    #[Test]
    public function aCoreStepIsRefused(): void
    {
        foreach ([null, self::SHOP_TEAM] as $teamId) {
            self::assertSame(
                [CoreStepNotRemovable::class, 'Code review is a core step, which every team keeps.'],
                $this->refusalAs(self::ACME_FOODS, self::ACME_FOODS, $teamId, self::CODE_REVIEW, 0),
            );
        }
    }

    #[Test]
    public function aStepWithOpenTicketsIsRefused(): void
    {
        self::assertSame(
            [StepHasOpenTickets::class, '2 open tickets are still in this step. Move them first.'],
            $this->refusalAs(self::ACME_FOODS, self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_READY_TO_MERGE, 2),
        );
    }

    #[Test]
    public function anUnknownOrMalformedStepIdIsRefused(): void
    {
        foreach ([self::UNKNOWN_STEP, 'not-a-step-id', ''] as $stepId) {
            self::assertSame(
                [UnknownStep::class, 'That step is not in this organization.'],
                $this->refusalAs(self::ACME_FOODS, self::ACME_FOODS, null, $stepId, 0),
                $stepId,
            );
        }
    }

    /**
     * Under Beta Bakery the step is not found at all; under Acme Foods with Beta Bakery named as the organization, the
     * step is found but is not Beta Bakery's.
     */
    #[Test]
    public function anotherOrganizationsStepIsRefused(): void
    {
        self::assertSame(
            [UnknownStep::class, 'That step is not in this organization.'],
            $this->refusalAs(self::BETA_BAKERY, self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_READY_TO_MERGE, 0),
        );
        self::assertSame(
            [UnknownStep::class, 'That step is not in this organization.'],
            $this->refusalAs(self::ACME_FOODS, self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_READY_TO_MERGE, 0),
        );
    }

    /**
     * The voter granted the lead STEP_CHANGE_TEAM on their own team only, so the step must be one of that team's.
     */
    #[Test]
    public function aTeamRemovesNeitherAnotherTeamsStepNorACompanyStep(): void
    {
        self::assertSame(
            [UnknownStep::class, 'That step is not one of this team\'s steps.'],
            $this->refusalAs(self::ACME_FOODS, self::ACME_FOODS, self::SHOP_TEAM, self::MOBILE_DEVICE_CHECK, 0),
        );
        self::assertSame(
            [UnknownStep::class, 'That step is not one of this team\'s steps.'],
            $this->refusalAs(self::ACME_FOODS, self::ACME_FOODS, self::SHOP_TEAM, self::DESIGN_CHECK, 0),
        );
    }

    /**
     * Without a team the caller was voted as an Admin of company steps, so a team step is refused too.
     */
    #[Test]
    public function withoutATeamATeamStepIsRefused(): void
    {
        self::assertSame(
            [UnknownStep::class, 'That step is not a company step.'],
            $this->refusalAs(self::ACME_FOODS, self::ACME_FOODS, null, self::SHOP_READY_TO_MERGE, 0),
        );
    }

    /**
     * @return array{class-string, string}|null the refusal's class and message; null when the step was removed
     */
    private function refusalAs(string $tenantId, string $organizationId, ?string $teamId, string $stepId, int $openTickets): ?array
    {
        [$refusal, $archivedCount] = $this->transactions()->rolledBackAs($tenantId, function () use ($organizationId, $teamId, $stepId, $openTickets): array {
            try {
                $this->removeStep(new FixedStepOccupancy($openTickets))(
                    new OrganizationId($organizationId),
                    null === $teamId ? null : new TeamId($teamId),
                    $stepId,
                    new \DateTimeImmutable(self::NOW),
                );
                $refusal = null;
            } catch (\DomainException $exception) {
                $refusal = [$exception::class, $exception->getMessage()];
            }

            return [$refusal, $this->connection()->fetchOne('SELECT count(*) FROM wf_step WHERE archived_at IS NOT NULL')];
        });
        self::assertSame(0, $archivedCount, 'A refused removal archives nothing.');
        self::assertSame([], $this->events->published, 'A refused removal publishes nothing.');

        return $refusal;
    }

    private function removeStep(FixedStepOccupancy $stepOccupancy): RemoveStep
    {
        return new RemoveStep(new DoctrineSteps($this->entityManager()), $stepOccupancy, $this->events);
    }

    private function archivedAtOf(string $stepId): mixed
    {
        return $this->connection()->fetchOne("SELECT to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM wf_step WHERE id = ?", [$stepId]);
    }

    private function insertStep(string $stepId, ?string $teamId, string $name, ?string $coreCode, string $origin, int $position): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, ?, 'check', ?, ?, ?)",
            [self::ACME_FOODS, $stepId, $teamId, $name, $coreCode, $origin, $position],
        );
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
