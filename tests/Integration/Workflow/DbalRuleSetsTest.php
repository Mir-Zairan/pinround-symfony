<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRuleSets on the real wf_rule_set table as pinround_app, under row-level security.
 */
final class DbalRuleSetsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000018a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000018b1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000018c1';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-0000000018c2';

    private ?Connection $secondConnection = null;

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    /**
     * A failed race test may leave both sessions in a transaction holding row locks, which would block the cleanup.
     */
    protected function tearDown(): void
    {
        $this->secondConnection?->close();
        $this->secondConnection = null;
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theOrganizationRuleSetIsSavedAndReadBack(): void
    {
        [$found, $storedRows] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true));

            return [
                $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS)),
                $this->connection()->fetchAllAssociative('SELECT organization_id, team_id, tester_moves_done, no_self_review, review_before_test FROM wf_rule_set'),
            ];
        });

        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true), $found);
        self::assertSame(
            [['organization_id' => self::ACME_FOODS, 'team_id' => null, 'tester_moves_done' => true, 'no_self_review' => false, 'review_before_test' => true]],
            $storedRows,
        );
    }

    #[Test]
    public function savingAgainReplacesTheOrganizationRuleSet(): void
    {
        [$found, $rowCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), RuleSet::defaults());
            $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false));

            return [
                $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS)),
                $this->connection()->fetchOne('SELECT count(*) FROM wf_rule_set'),
            ];
        });

        self::assertEquals(new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false), $found);
        self::assertSame(1, $rowCount);
    }

    #[Test]
    public function anOrganizationWithoutARuleSetHasNone(): void
    {
        self::assertNull($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?RuleSet => $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS))));
    }

    /**
     * A team row has the organization's id too; only the row without a team is the organization's rule set, and saving
     * that one leaves the team row alone.
     */
    #[Test]
    public function aTeamRuleSetIsNeitherReadNorReplacedAsTheOrganizationRuleSet(): void
    {
        [$beforeSave, $afterSave, $teamRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->connection()->executeStatement(
                'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, ?, false, false, false)',
                [self::ACME_FOODS, self::SHOP_TEAM],
            );
            $beforeSave = $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS));
            $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), RuleSet::defaults());

            return [
                $beforeSave,
                $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS)),
                $this->connection()->fetchAssociative('SELECT tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE team_id = ?', [self::SHOP_TEAM]),
            ];
        });

        self::assertNull($beforeSave);
        self::assertEquals(RuleSet::defaults(), $afterSave);
        self::assertSame(['tester_moves_done' => false, 'no_self_review' => false, 'review_before_test' => false], $teamRow);
    }

    #[Test]
    public function switchingARuleOfATeamWithoutARuleSetAddsItFromTheStartingRulesForThatTeamOnly(): void
    {
        [$found, $otherTeamFound, $storedRows] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->ruleSets()->switchRule(
                new OrganizationId(self::ACME_FOODS),
                new TeamId(self::SHOP_TEAM),
                'tester_moves_done',
                false,
                new RuleSet(testerMovesDone: true, noSelfReview: true, reviewBeforeTest: false),
            );

            return [
                $this->ruleSets()->forTeam(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM)),
                $this->ruleSets()->forTeam(new OrganizationId(self::ACME_FOODS), new TeamId(self::MOBILE_TEAM)),
                $this->connection()->fetchAllAssociative('SELECT organization_id, team_id, tester_moves_done, no_self_review, review_before_test FROM wf_rule_set'),
            ];
        });

        self::assertEquals(new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false), $found);
        self::assertNull($otherTeamFound);
        self::assertSame(
            [['organization_id' => self::ACME_FOODS, 'team_id' => self::SHOP_TEAM, 'tester_moves_done' => false, 'no_self_review' => true, 'review_before_test' => false]],
            $storedRows,
        );
    }

    #[Test]
    public function switchingATeamRuleLeavesTheOrganizationRuleSetUnchanged(): void
    {
        [$organizationRuleSet, $organizationRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), RuleSet::defaults());
            $this->ruleSets()->switchRule(
                new OrganizationId(self::ACME_FOODS),
                new TeamId(self::SHOP_TEAM),
                'no_self_review',
                false,
                new RuleSet(testerMovesDone: false, noSelfReview: false, reviewBeforeTest: false),
            );

            return [
                $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS)),
                $this->connection()->fetchAllAssociative('SELECT tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE team_id IS NULL'),
            ];
        });

        self::assertEquals(RuleSet::defaults(), $organizationRuleSet);
        self::assertSame(
            [[
                'tester_moves_done' => RuleSet::defaults()->testerMovesDone,
                'no_self_review' => RuleSet::defaults()->noSelfReview,
                'review_before_test' => RuleSet::defaults()->reviewBeforeTest,
            ]],
            $organizationRow,
        );
    }

    /**
     * The starting rules differ from the stored row in every rule, so writing more than the switched rule would show.
     */
    #[Test]
    public function switchingARuleOfAnExistingRuleSetWritesThatRuleOnlyInItsOneRow(): void
    {
        [$foundForTeam, $foundForOrganization, $rowCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $acmeFoods = new OrganizationId(self::ACME_FOODS);
            $this->ruleSets()->saveForOrganization($acmeFoods, RuleSet::defaults());
            $this->ruleSets()->switchRule($acmeFoods, new TeamId(self::SHOP_TEAM), 'tester_moves_done', false, RuleSet::defaults());
            $staleStartingRules = new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true);
            $this->ruleSets()->switchRule($acmeFoods, new TeamId(self::SHOP_TEAM), 'review_before_test', false, $staleStartingRules);
            $this->ruleSets()->switchRule($acmeFoods, null, 'no_self_review', false, new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false));

            return [
                $this->ruleSets()->forTeam($acmeFoods, new TeamId(self::SHOP_TEAM)),
                $this->ruleSets()->forOrganization($acmeFoods),
                $this->connection()->fetchOne('SELECT count(*) FROM wf_rule_set'),
            ];
        });

        self::assertEquals(new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false), $foundForTeam);
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true), $foundForOrganization);
        self::assertSame(2, $rowCount);
    }

    #[Test]
    public function anUnknownRuleIsRefusedAndNothingIsWritten(): void
    {
        [$refusal, $rowCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            try {
                $this->ruleSets()->switchRule(new OrganizationId(self::ACME_FOODS), null, 'tester_moves_done = false, no_self_review', false, RuleSet::defaults());
                $refusal = null;
            } catch (\InvalidArgumentException $exception) {
                $refusal = $exception->getMessage();
            }

            return [$refusal, $this->connection()->fetchOne('SELECT count(*) FROM wf_rule_set')];
        });

        self::assertSame('There is no rule "tester_moves_done = false, no_self_review".', $refusal);
        self::assertSame(0, $rowCount);
    }

    /**
     * Two people switch different rules of the same rule set at the same time: both read it, then each switches one
     * rule and commits. The second commit must keep the first one's rule, for the organization's row and for a team row
     * both sessions add at once.
     */
    #[Test]
    public function twoSessionsSwitchingDifferentRulesAtOnceKeepBothChanges(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $ruleSets = $this->ruleSets();
        $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $ruleSets->saveForOrganization($acmeFoods, RuleSet::defaults()));
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->secondConnection = $secondSession->entityManager->getConnection();
        $secondRuleSets = new DbalRuleSets($this->secondConnection);

        $this->tenantContext()->set($acmeFoods);
        foreach ([null, new TeamId(self::SHOP_TEAM)] as $teamId) {
            $this->connection()->beginTransaction();
            $this->secondConnection->beginTransaction();
            $firstStartingRules = $ruleSets->forOrganization($acmeFoods);
            $secondStartingRules = $secondRuleSets->forOrganization($acmeFoods);
            self::assertNotNull($firstStartingRules);
            self::assertNotNull($secondStartingRules);

            $ruleSets->switchRule($acmeFoods, $teamId, 'no_self_review', false, $firstStartingRules);
            $this->connection()->commit();
            $secondRuleSets->switchRule($acmeFoods, $teamId, 'review_before_test', false, $secondStartingRules);
            $this->secondConnection->commit();
        }
        $this->tenantContext()->clear();

        [$organizationRules, $teamRules] = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): array => [
            $ruleSets->forOrganization($acmeFoods),
            $ruleSets->forTeam($acmeFoods, new TeamId(self::SHOP_TEAM)),
        ]);
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: false), $organizationRules);
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: false), $teamRules);
    }

    /**
     * Acme Foods' team row is committed first, so the refused write is checked against a row that really exists, and
     * the write is committed, so a write row-level security failed to stop would stay for the follow-up read to find.
     */
    #[Test]
    public function anotherOrganizationNeitherReadsNorSwitchesATeamRuleOfTheOrganization(): void
    {
        $acmeTeamRules = new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true);
        $ruleSets = $this->ruleSets();
        $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $ruleSets->switchRule(
            new OrganizationId(self::ACME_FOODS),
            new TeamId(self::SHOP_TEAM),
            'no_self_review',
            false,
            RuleSet::defaults(),
        ));

        $foundByBetaBakery = $this->transactions()->rolledBackAs(self::BETA_BAKERY, static fn (): ?RuleSet => $ruleSets->forTeam(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM)));
        try {
            $this->transactions()->committedAs(self::BETA_BAKERY, static fn () => $ruleSets->switchRule(
                new OrganizationId(self::ACME_FOODS),
                new TeamId(self::SHOP_TEAM),
                'tester_moves_done',
                false,
                RuleSet::defaults(),
            ));
            self::fail('Beta Bakery saved a team rule set in the name of Acme Foods.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "wf_rule_set"', $refusal->getMessage());
        }

        self::assertNull($foundByBetaBakery);
        self::assertEquals($acmeTeamRules, $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): ?RuleSet => $ruleSets->forTeam(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM))));
        self::assertSame(0, $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM wf_rule_set')));
    }

    /**
     * Without a transaction no tenant is set, so a read would see no row and a write would be refused.
     */
    #[Test]
    public function readingOrSavingOutsideATransactionIsRefused(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            foreach ([
                'read' => fn (): ?RuleSet => $this->ruleSets()->forOrganization(new OrganizationId(self::ACME_FOODS)),
                'save' => fn () => $this->ruleSets()->saveForOrganization(new OrganizationId(self::ACME_FOODS), RuleSet::defaults()),
                'switch' => fn () => $this->ruleSets()->switchRule(new OrganizationId(self::ACME_FOODS), null, 'no_self_review', false, RuleSet::defaults()),
            ] as $what => $work) {
                try {
                    $work();
                    self::fail(\sprintf('The rule set %s ran outside a transaction.', $what));
                } catch (\LogicException) {
                }
            }
        } finally {
            $tenantContext->clear();
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM wf_rule_set')));
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
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function rows(): WorkflowRows
    {
        return new WorkflowRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
