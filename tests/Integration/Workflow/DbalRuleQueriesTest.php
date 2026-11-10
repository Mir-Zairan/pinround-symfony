<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Workflow\Infrastructure\Doctrine\DbalRuleQueries;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRuleQueries as pinround_app on committed wf_rule_set and org_settings rows, in a transaction under a tenant, as
 * inside a request. The organization's rules and the team's differ in every rule, so a wrong pick shows in each one.
 */
final class DbalRuleQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001da1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000001db1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000001dd1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000001dd2';
    private const string BETA_TASTING_TEAM = '0197a3c0-0000-7000-8000-000000001dd3';

    private const array ORGANIZATION_RULES = [true, false, true];
    private const array SHOP_RULES = [false, true, false];
    private const array BETA_RULES = [false, false, false];

    protected function setUp(): void
    {
        $this->tearDownRows();
        $this->addRuleSet(self::ACME_FOODS, null, self::ORGANIZATION_RULES);
        $this->addRuleSet(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_RULES);
        $this->addRuleSet(self::BETA_BAKERY, null, self::BETA_RULES);
        $this->addRuleSet(self::BETA_BAKERY, self::BETA_TASTING_TEAM, [true, true, true]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->tearDownRows();
        parent::tearDown();
    }

    #[Test]
    public function underTeamTheTeamsOwnRulesApply(): void
    {
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Team);

        self::assertSame(self::SHOP_RULES, $this->rulesAs(self::ACME_FOODS, self::SHOP_TEAM));
    }

    #[Test]
    public function underTeamATeamWithoutRulesOfItsOwnGetsTheOrganizationsRules(): void
    {
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Team);

        self::assertSame(self::ORGANIZATION_RULES, $this->rulesAs(self::ACME_FOODS, self::MOBILE_TEAM));
    }

    #[Test]
    public function underExtendAndLockedAndWithNoSettingsTheOrganizationsRulesApplyEvenToATeamWithARow(): void
    {
        self::assertSame(self::ORGANIZATION_RULES, $this->rulesAs(self::ACME_FOODS, self::SHOP_TEAM), 'No settings row means extend.');

        $this->policies()->set(self::ACME_FOODS, StepPolicy::Extend);
        self::assertSame(self::ORGANIZATION_RULES, $this->rulesAs(self::ACME_FOODS, self::SHOP_TEAM));

        $this->organizationRows()->removeAllOf(self::ACME_FOODS);
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Locked);
        self::assertSame(self::ORGANIZATION_RULES, $this->rulesAs(self::ACME_FOODS, self::SHOP_TEAM));
    }

    #[Test]
    public function anOrganizationWithoutAnyRuleSetHasAllThreeRulesOn(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);

        self::assertSame([true, true, true], $this->rulesAs(self::ACME_FOODS, self::SHOP_TEAM));
    }

    #[Test]
    public function anotherOrganizationsRulesAreNeverUsed(): void
    {
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Team);
        $this->policies()->set(self::BETA_BAKERY, StepPolicy::Team);

        self::assertSame(self::ORGANIZATION_RULES, $this->rulesAs(self::ACME_FOODS, self::BETA_TASTING_TEAM), 'A team of another organization gets this organization\'s rules.');
        self::assertSame(self::BETA_RULES, $this->rulesAs(self::BETA_BAKERY, self::SHOP_TEAM));
    }

    /**
     * DbalRuleSets runs plain SQL, which the ORM tenant filter never touches; row-level security alone keeps the
     * organizations apart.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesTheOtherOrganizationsRulesWithTheTenantFilterOff(): void
    {
        $this->policies()->set(self::BETA_BAKERY, StepPolicy::Team);
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $rules = $this->rulesAs(self::BETA_BAKERY, self::SHOP_TEAM);

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame(self::BETA_RULES, $rules);
    }

    #[Test]
    public function rulesUnderFollowsThePassedPolicyNotTheStoredOne(): void
    {
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Team);
        self::assertSame(self::ORGANIZATION_RULES, $this->rulesUnder(StepPolicy::Extend), 'Stored team, passed extend: the organization\'s rules.');

        $this->organizationRows()->removeAllOf(self::ACME_FOODS);
        $this->policies()->set(self::ACME_FOODS, StepPolicy::Extend);
        self::assertSame(self::SHOP_RULES, $this->rulesUnder(StepPolicy::Team), 'Stored extend, passed team: the team\'s rules.');
    }

    #[Test]
    public function withNoTenantTheRulesAreRefused(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->connection()->beginTransaction();
        try {
            $this->queries()->rulesFor(new TeamId(self::SHOP_TEAM));
        } finally {
            $this->connection()->rollBack();
        }
    }

    /**
     * @return array{bool, bool, bool}
     */
    private function rulesUnder(StepPolicy $policy): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($policy): array {
            $rules = $this->queries()->rulesUnder(new TeamId(self::SHOP_TEAM), $policy);

            return [$rules->testerMovesDone, $rules->noSelfReview, $rules->reviewBeforeTest];
        });
    }

    /**
     * @return array{bool, bool, bool} tester_moves_done, no_self_review, review_before_test
     */
    private function rulesAs(string $tenantId, string $teamId): array
    {
        return $this->transactions()->rolledBackAs($tenantId, function () use ($teamId): array {
            $rules = $this->queries()->rulesFor(new TeamId($teamId));

            return [$rules->testerMovesDone, $rules->noSelfReview, $rules->reviewBeforeTest];
        });
    }

    /**
     * @param array{bool, bool, bool} $rules
     */
    private function addRuleSet(string $organizationId, ?string $teamId, array $rules): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->connection()->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, ?, ?, ?, ?)',
            [$organizationId, $teamId, ...$rules],
            [2 => ParameterType::BOOLEAN, 3 => ParameterType::BOOLEAN, 4 => ParameterType::BOOLEAN],
        ));
    }

    private function tearDownRows(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->organizationRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function queries(): DbalRuleQueries
    {
        return new DbalRuleQueries(new DbalRuleSets($this->connection()), new DbalSettingsQueries($this->connection()), $this->tenantContext());
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

    private function organizationRows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
