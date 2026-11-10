<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\Step;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\StepOrigin;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineSteps on the real wf_step table as pinround_app, under row-level security.
 */
final class DoctrineStepsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000017a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000017c1';
    private const string CORE_STEP = '0197a3c0-0000-7000-8000-0000000017d1';
    private const string COMPANY_STEP = '0197a3c0-0000-7000-8000-0000000017d2';
    private const string TEAM_STEP = '0197a3c0-0000-7000-8000-0000000017d3';
    private const string SECOND_CORE_STEP = '0197a3c0-0000-7000-8000-0000000017d4';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function aCoreACompanyAndATeamStepAreSavedInOneFlushAndFoundById(): void
    {
        [$flushes, $found, $storedCoreRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $steps = $this->steps();
            $flushes = FlushCounter::countDuring($this->entityManager(), static fn () => $steps->add(
                new Step(new StepId(self::CORE_STEP), new OrganizationId(self::ACME_FOODS), null, StepOrigin::Core, CoreStep::Review, 'Code review', Group::Check, 3),
                new Step(new StepId(self::COMPANY_STEP), new OrganizationId(self::ACME_FOODS), null, StepOrigin::Company, null, 'Design check', Group::Check, 4),
                new Step(new StepId(self::TEAM_STEP), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), StepOrigin::Team, null, '  Ready to ship  ', Group::Wait, 7),
            ));
            $this->entityManager()->clear();

            return [
                $flushes,
                array_map(static function (string $id) use ($steps): array {
                    $step = $steps->byId(new StepId($id));
                    self::assertNotNull($step, $id);

                    return [$step->id()->toString(), $step->organizationId()->toString(), $step->teamId()?->toString(), $step->origin(), $step->coreCode(), $step->name(), $step->group(), $step->position()];
                }, [self::CORE_STEP, self::COMPANY_STEP, self::TEAM_STEP]),
                $this->connection()->fetchAssociative('SELECT organization_id, team_id, name, grp, core_code, origin, position, archived_at FROM wf_step WHERE id = ?', [self::CORE_STEP]),
            ];
        });

        self::assertSame(1, $flushes);
        self::assertSame(
            [
                [self::CORE_STEP, self::ACME_FOODS, null, StepOrigin::Core, CoreStep::Review, 'Code review', Group::Check, 3],
                [self::COMPANY_STEP, self::ACME_FOODS, null, StepOrigin::Company, null, 'Design check', Group::Check, 4],
                [self::TEAM_STEP, self::ACME_FOODS, self::SHOP_TEAM, StepOrigin::Team, null, 'Ready to ship', Group::Wait, 7],
            ],
            $found,
        );
        self::assertSame(
            ['organization_id' => self::ACME_FOODS, 'team_id' => null, 'name' => 'Code review', 'grp' => 'check', 'core_code' => 'review', 'origin' => 'core', 'position' => 3, 'archived_at' => null],
            $storedCoreRow,
        );
    }

    #[Test]
    public function anUnknownIdFindsNoStep(): void
    {
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Step => $this->steps()->byId(new StepId(self::CORE_STEP))));
    }

    /**
     * Core steps have no team, so the unique index only catches the second one because NULL team ids count as equal.
     */
    #[Test]
    public function aSecondCoreStepWithTheSameCodeIsRefused(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->steps()->add(
            new Step(new StepId(self::CORE_STEP), new OrganizationId(self::ACME_FOODS), null, StepOrigin::Core, CoreStep::Todo, 'To do', Group::Todo, 1),
        ));

        try {
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->steps()->add(
                new Step(new StepId(self::SECOND_CORE_STEP), new OrganizationId(self::ACME_FOODS), null, StepOrigin::Core, CoreStep::Todo, 'To do', Group::Todo, 2),
            ));
            self::fail('A second core To do step was saved.');
        } catch (UniqueConstraintViolationException $refusal) {
            self::assertStringContainsString('wf_step_organization_id_team_id_core_code_key', $refusal->getMessage());
        }
    }

    #[Test]
    public function aStepNeverAddedNorLoadedIsNotSaved(): void
    {
        $storedRows = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int|string {
            try {
                $this->steps()->save(
                    new Step(new StepId(self::CORE_STEP), new OrganizationId(self::ACME_FOODS), null, StepOrigin::Core, CoreStep::Todo, 'To do', Group::Todo, 1),
                );
                self::fail('A step that was never added was saved.');
            } catch (\LogicException $refusal) {
                self::assertSame('Only a step found through byId or added before is saved.', $refusal->getMessage());
            }

            $rows = $this->connection()->fetchOne('SELECT COUNT(*) FROM wf_step WHERE id = ?', [self::CORE_STEP]);
            self::assertTrue(\is_int($rows) || \is_string($rows));

            return $rows;
        });

        self::assertSame(0, (int) $storedRows);
    }

    #[Test]
    public function nextIdentityGivesADifferentStepIdEachTime(): void
    {
        self::assertFalse($this->steps()->nextIdentity()->equals($this->steps()->nextIdentity()));
    }

    private function steps(): DoctrineSteps
    {
        return new DoctrineSteps($this->entityManager());
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
