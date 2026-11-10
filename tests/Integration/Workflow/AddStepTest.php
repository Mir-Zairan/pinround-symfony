<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Workflow\Application\FixedStepPolicy;
use App\Workflow\Api\StepView;
use App\Workflow\Application\AddStep;
use App\Workflow\Application\SetUpOrganizationWorkflow;
use App\Workflow\Application\StepGroupUnknown;
use App\Workflow\Application\StepInDoneRefused;
use App\Workflow\Application\StepNameMissing;
use App\Workflow\Application\StepTeamNotFound;
use App\Workflow\Application\TeamStepsLocked;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use App\Workflow\Infrastructure\Doctrine\DbalStepQueries;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AddStep on the real wf_step and org_team tables as pinround_app, after the organization got its core steps and its
 * SHOP and Mobile teams, each test in one rolled-back transaction under Acme Foods.
 */
final class AddStepTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ea1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000001ec1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000001ec2';
    private const string MOBILE_DEVICE_CHECK = '0197a3c0-0000-7000-8000-000000001ed1';
    private const string REMOVED_TRIAGE = '0197a3c0-0000-7000-8000-000000001ed2';
    private const string UNKNOWN_TEAM = '0197a3c0-0000-7000-8000-000000001ec3';
    private const string LENA = '0197a3c0-0000-7000-8000-000000001eb1';

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    #[Test]
    public function aCompanyStepInCheckingComesAfterTheCoreReviewAndTestSteps(): void
    {
        [$stepId, $checking, $storedRow] = $this->inAcmeFoods(function (): array {
            $stepId = $this->addStep(StepPolicy::Extend)(new OrganizationId(self::ACME_FOODS), null, '  Design check ', 'check');

            return [
                $stepId,
                $this->checkingStepsFor(self::SHOP_TEAM),
                $this->connection()->fetchAssociative('SELECT team_id, origin, core_code, archived_at FROM wf_step WHERE id = ?', [$stepId]),
            ];
        });

        self::assertSame([['Code review', 'core', 3], ['Testing', 'core', 4], ['Design check', 'company', 5]], $checking);
        self::assertSame(['team_id' => null, 'origin' => 'company', 'core_code' => null, 'archived_at' => null], $storedRow);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7/', $stepId);
    }

    /**
     * The position counts the steps the new one is listed with only: another team's steps and removed steps are left
     * out (their high positions would show), and a team step comes after the company steps even when a company step
     * added later has the same position.
     */
    #[Test]
    public function aTeamStepIsListedAfterTheCompanyStepsOfItsGroup(): void
    {
        [$shopChecking, $mobileChecking] = $this->inAcmeFoods(function (): array {
            $this->insertStep(self::MOBILE_DEVICE_CHECK, self::MOBILE_TEAM, 'Device check', 'team', 40, null);
            $this->insertStep(self::REMOVED_TRIAGE, null, 'Triage', 'company', 30, '2027-03-01 09:00:00+00');
            $addStep = $this->addStep(StepPolicy::Extend);
            $addStep(new OrganizationId(self::ACME_FOODS), null, 'Design check', 'check');
            $addStep(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'Ready to merge', 'check');
            $addStep(new OrganizationId(self::ACME_FOODS), null, 'Security check', 'check');
            $addStep(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'Pairing review', 'check');

            return [$this->checkingStepsFor(self::SHOP_TEAM), $this->checkingStepsFor(self::MOBILE_TEAM)];
        });

        self::assertSame(
            [
                ['Code review', 'core', 3],
                ['Testing', 'core', 4],
                ['Design check', 'company', 5],
                ['Security check', 'company', 6],
                ['Ready to merge', 'team', 6],
                ['Pairing review', 'team', 7],
            ],
            $shopChecking,
        );
        self::assertSame(
            [['Code review', 'core', 3], ['Testing', 'core', 4], ['Design check', 'company', 5], ['Security check', 'company', 6], ['Device check', 'team', 40]],
            $mobileChecking,
        );
    }

    #[Test]
    public function aStepInAnEmptyGroupGetsPositionZero(): void
    {
        $positions = $this->inAcmeFoods(function (): array {
            $this->connection()->executeStatement("UPDATE wf_step SET archived_at = '2027-03-01 09:00:00+00' WHERE grp = 'doing'");
            $this->addStep(StepPolicy::Extend)(new OrganizationId(self::ACME_FOODS), null, 'Building', 'doing');

            return $this->connection()->fetchFirstColumn("SELECT position FROM wf_step WHERE grp = 'doing' AND archived_at IS NULL");
        });

        self::assertSame([0], $positions);
    }

    #[Test]
    public function underLockedACompanyStepIsAddedButATeamStepIsRefused(): void
    {
        [$refusal, $names] = $this->inAcmeFoods(function (): array {
            $addStep = $this->addStep(StepPolicy::Locked);
            $addStep(new OrganizationId(self::ACME_FOODS), null, 'Design check', 'check');
            try {
                $addStep(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'Ready to merge', 'check');
                $refusal = null;
            } catch (TeamStepsLocked $locked) {
                $refusal = $locked->getMessage();
            }

            return [$refusal, $this->connection()->fetchFirstColumn("SELECT name FROM wf_step WHERE origin <> 'core'")];
        });

        self::assertSame('Your admin has locked the steps, so teams cannot add their own.', $refusal);
        self::assertSame(['Design check'], $names);
    }

    #[Test]
    public function aTeamStepForATeamThatIsNotTheOrganizationsIsRefusedAndNothingIsSaved(): void
    {
        [$refusal, $stepCount] = $this->inAcmeFoods(function (): array {
            try {
                $this->addStep(StepPolicy::Team)(new OrganizationId(self::ACME_FOODS), new TeamId(self::UNKNOWN_TEAM), 'Ready to merge', 'check');
                $refusal = null;
            } catch (StepTeamNotFound $notFound) {
                $refusal = $notFound->getMessage();
            }

            return [$refusal, $this->connection()->fetchOne('SELECT count(*) FROM wf_step')];
        });

        self::assertSame('That team is not in this organization.', $refusal);
        self::assertSame(8, $stepCount, 'Only the core steps are saved.');
    }

    #[Test]
    public function aBlankNameAnUnknownGroupAndTheDoneGroupAreRefusedAndNothingIsSaved(): void
    {
        foreach ([
            [StepNameMissing::class, " \t ", 'check', 'A step needs a name.'],
            [StepGroupUnknown::class, 'Design check', 'review', 'There is no group "review".'],
            [StepInDoneRefused::class, 'Shipped', 'done', 'Only the core Done step is in the Done group.'],
        ] as [$refusalClass, $name, $groupCode, $message]) {
            [$refusal, $stepCount] = $this->inAcmeFoods(function () use ($name, $groupCode): array {
                try {
                    $this->addStep(StepPolicy::Team)(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), $name, $groupCode);
                    $refusal = null;
                } catch (\Exception $exception) {
                    $refusal = $exception;
                }

                return [$refusal, $this->connection()->fetchOne('SELECT count(*) FROM wf_step')];
            });

            self::assertInstanceOf($refusalClass, $refusal, $groupCode);
            self::assertSame($message, $refusal->getMessage());
            self::assertSame(8, $stepCount, 'Only the core steps are saved.');
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inAcmeFoods(\Closure $work): mixed
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($work): mixed {
            new SetUpOrganizationWorkflow(new DoctrineSteps($this->entityManager()), new DbalRuleSets($this->connection()))->setUp(new OrganizationId(self::ACME_FOODS));
            foreach ([self::SHOP_TEAM => 'SHOP', self::MOBILE_TEAM => 'Mobile'] as $teamId => $teamName) {
                $this->connection()->executeStatement(
                    'INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?)',
                    [self::ACME_FOODS, $teamId, $teamName, self::LENA, '2027-03-01 09:00:00+00'],
                );
            }

            return $work();
        });
    }

    private function addStep(StepPolicy $stepPolicy): AddStep
    {
        return new AddStep(new DoctrineSteps($this->entityManager()), new FixedStepPolicy($stepPolicy), new DbalTeamQueries($this->connection()));
    }

    /**
     * @return list<array{string, string, int}> name, origin and position of the Checking steps, in list order
     */
    private function checkingStepsFor(string $teamId): array
    {
        return array_values(array_map(
            static fn (StepView $step): array => [$step->name(), $step->origin(), $step->position()],
            array_filter(new DbalStepQueries($this->connection())->stepsFor(new TeamId($teamId)), static fn (StepView $step): bool => 'check' === $step->groupCode()),
        ));
    }

    private function insertStep(string $stepId, ?string $teamId, string $name, string $origin, int $position, ?string $archivedAt): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, ?, ?, 'check', NULL, ?, ?, ?)",
            [self::ACME_FOODS, $stepId, $teamId, $name, $origin, $position, $archivedAt],
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
}
