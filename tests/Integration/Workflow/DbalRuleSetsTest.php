<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Workflow\Domain\RuleSet;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRuleSets on the real wf_rule_set table as pinround_app, under row-level security.
 */
final class DbalRuleSetsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000018a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000018c1';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
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
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): WorkflowRows
    {
        return new WorkflowRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
