<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Workflow\Application\FixedStepOccupancy;
use App\Tests\Unit\Workflow\Application\FixedStepPolicy;
use App\Workflow\Api\Event\RulesChanged;
use App\Workflow\Api\Event\StepAdded;
use App\Workflow\Api\Event\StepRemoved;
use App\Workflow\Application\AddStep;
use App\Workflow\Application\RemoveStep;
use App\Workflow\Application\SetRule;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use App\Workflow\Infrastructure\Doctrine\DbalRuleSets;
use App\Workflow\Infrastructure\Doctrine\DoctrineSteps;
use App\Workflow\Infrastructure\Messenger\MessengerWorkflowEvents;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Workflow events of AddStep, RemoveStep and SetRule on the real event.bus and outbox (messenger.yaml): a command
 * writes its event to the outbox in its transaction, so a rollback leaves none.
 */
final class WorkflowEventOutboxTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002001';
    private const string DESIGN_CHECK = '0197a3c0-0000-7000-8000-000000002011';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        parent::tearDown();
    }

    #[Test]
    public function aCommittedAddStepLeavesOneStepAddedInTheOutbox(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->addStep()(new OrganizationId(self::ACME_FOODS), null, 'Design check', 'check'));

        self::assertSame([StepAdded::class], $this->outboxEventClasses());
    }

    #[Test]
    public function aCommittedRemoveStepLeavesOneStepRemovedInTheOutbox(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->insertCompanyStep();
            $this->removeStep()(new OrganizationId(self::ACME_FOODS), null, self::DESIGN_CHECK, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        });

        self::assertSame([StepRemoved::class], $this->outboxEventClasses());
    }

    #[Test]
    public function aCommittedSetRuleLeavesOneRulesChangedInTheOutbox(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->setRule()(new OrganizationId(self::ACME_FOODS), null, 'no_self_review', false));

        self::assertSame([RulesChanged::class], $this->outboxEventClasses());
    }

    #[Test]
    public function aRolledBackCommandLeavesNoEventInTheOutbox(): void
    {
        $insideTheTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->addStep()(new OrganizationId(self::ACME_FOODS), null, 'Design check', 'check');
            $this->setRule()(new OrganizationId(self::ACME_FOODS), null, 'no_self_review', false);

            return $this->outboxEventClasses();
        });

        self::assertSame([StepAdded::class, RulesChanged::class], $insideTheTransaction);
        self::assertSame([], $this->outboxEventClasses());
    }

    #[Test]
    public function publishingOutsideATransactionIsRefusedAndWritesNoOutboxRow(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->events()->publish(new RulesChanged(self::ACME_FOODS, null, 'no_self_review', false));
            self::fail('An event was published with no transaction open.');
        } catch (\LogicException $refused) {
            self::assertSame('A workflow event is published in the transaction of its change; none is open.', $refused->getMessage());
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame([], $this->outboxEventClasses());
    }

    /**
     * @return list<class-string> the event class of each outbox row, oldest first
     */
    private function outboxEventClasses(): array
    {
        $classes = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' ORDER BY id") as $body) {
            self::assertIsString($body);
            foreach ([StepAdded::class, StepRemoved::class, RulesChanged::class] as $eventClass) {
                // The transport stores the serialized envelope with its quotes and backslashes escaped.
                if (str_contains(str_replace('\\', '', $body), '"'.str_replace('\\', '', $eventClass).'"')) {
                    $classes[] = $eventClass;
                }
            }
        }

        return $classes;
    }

    private function insertCompanyStep(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Design check', 'check', NULL, 'company', 0)",
            [self::ACME_FOODS, self::DESIGN_CHECK],
        );
    }

    private function addStep(): AddStep
    {
        return new AddStep(new DoctrineSteps($this->entityManager()), new FixedStepPolicy(StepPolicy::Extend), new DbalTeamQueries($this->connection()), $this->events());
    }

    private function removeStep(): RemoveStep
    {
        return new RemoveStep(new DoctrineSteps($this->entityManager()), new FixedStepOccupancy(0), $this->events());
    }

    private function setRule(): SetRule
    {
        return new SetRule(new DbalRuleSets($this->connection()), new FixedStepPolicy(StepPolicy::Extend), new DbalTeamQueries($this->connection()), $this->events());
    }

    private function events(): MessengerWorkflowEvents
    {
        return new MessengerWorkflowEvents(self::getContainer()->get('event.bus'), $this->connection(), self::getContainer()->get(WorkflowCache::class));
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
