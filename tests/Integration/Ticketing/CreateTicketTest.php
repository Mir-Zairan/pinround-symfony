<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Insight\Api\TurnProjector;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Insight\InMemoryTicketTurnSource;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Workflow\WorkflowRows;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateTicket\CreateTicket;
use App\Ticketing\Application\CreateTicket\ProjectHasNoTeam;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Infrastructure\Doctrine\DbalNextTicketNumber;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketHistory;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use App\Ticketing\Infrastructure\Messenger\MessengerTicketEvents;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CreateTicket on the real tables, outbox and turn projector, as pinround_app under row-level security: the ticket,
 * its created row, its turn row and TicketCreated are written in the caller's transaction, so a rollback leaves none.
 * The turn facts come from InMemoryTicketTurnSource, so the ticket id is fixed to put them there before the create.
 */
final class CreateTicketTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c701';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000c711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c721';
    private const string TEAMLESS_PROJECT = '0197a3c0-0000-7000-8000-00000000c722';
    private const string BACKLOG_STEP = '0197a3c0-0000-7000-8000-00000000c731';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-00000000c732';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c741';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c753';
    private const string TITLE = 'Cart total shows the wrong sum';
    private const int SHOP_NEXT_NUMBER = 7;

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, 'SHOP', self::SHOP_NEXT_NUMBER);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            foreach ([[self::BACKLOG_STEP, 'Backlog', 'backlog', 0], [self::TO_DO_STEP, 'To do', 'todo', 1]] as [$stepId, $name, $coreCode, $position]) {
                $this->connection()->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)",
                    [self::ACME_FOODS, $stepId, $name, $coreCode, $position],
                );
            }
        });
        $this->turnSource()->put(new TicketTurnFacts(
            new TicketId(self::TICKET),
            self::SHOP_PROJECT,
            new TicketState(self::TO_DO_STEP, 'todo', 'todo', new TeamId(self::SHOP_TEAM), assigneeId: new UserId(self::OMAR), reporterId: new UserId(self::MIA), priority: 'major'),
            new TeamFacts(new UserId(self::LENA), 'Lena'),
            'all',
        ));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->turnSource()->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aCommittedCreateWritesTheTicketItsCreatedRowItsTurnRowAndOneTicketCreatedWithoutItsText(): void
    {
        $key = $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->createdShopTicket($this->createTicket()));

        self::assertSame('SHOP-'.self::SHOP_NEXT_NUMBER, $key);
        self::assertSame([['key' => $key, 'step_id' => self::TO_DO_STEP]], $this->storedTickets());
        self::assertSame([['type' => 'created', 'to_step' => self::TO_DO_STEP, 'at' => '2027-03-15 10:00:00']], $this->storedCreatedRows());
        self::assertSame(1, $this->storedTurnRows());
        self::assertSame(self::SHOP_NEXT_NUMBER + 1, $this->storedNextNumber(self::SHOP_PROJECT));
        $outboxBodies = $this->outboxBodiesOf(TicketCreated::class);
        self::assertCount(1, $outboxBodies);
        self::assertStringNotContainsString(self::TITLE, $outboxBodies[0]);
        self::assertSame(1, $this->outboxRows());
    }

    #[Test]
    public function aThrowAfterThePublishLeavesNoTicketNoHistoryNoTurnNoEventAndTheNumberUntaken(): void
    {
        try {
            $this->transactions()->committedAs(self::ACME_FOODS, function (): never {
                $this->createdShopTicket($this->createTicket());
                throw new \RuntimeException('Forced after the publish.');
            });
        } catch (\RuntimeException $forced) {
            self::assertSame('Forced after the publish.', $forced->getMessage());
        }
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([], $this->storedTickets());
        self::assertSame([], $this->storedCreatedRows());
        self::assertSame(0, $this->storedTurnRows());
        self::assertSame(0, $this->outboxRows());
        self::assertSame(self::SHOP_NEXT_NUMBER, $this->storedNextNumber(self::SHOP_PROJECT));
    }

    #[Test]
    public function aProjectWithNoTeamIsRefusedAndWritesNothing(): void
    {
        $this->ticketRows()->addKeyedProjectOf(self::ACME_FOODS, self::TEAMLESS_PROJECT, null, 'APP', 3);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->createTicket()->create(
                new OrganizationId(self::ACME_FOODS),
                new UserId(self::MIA),
                self::TEAMLESS_PROJECT,
                self::TITLE,
                '',
                'bug',
                'major',
                null,
                new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
            ));
            self::fail('A ticket was created in a project with no team.');
        } catch (ProjectHasNoTeam $refused) {
            self::assertSame('That project has no team, so it takes no tickets.', $refused->getMessage());
        }
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([], $this->storedTickets());
        self::assertSame([], $this->storedCreatedRows());
        self::assertSame(0, $this->storedTurnRows());
        self::assertSame(0, $this->outboxRows());
        self::assertSame(3, $this->storedNextNumber(self::TEAMLESS_PROJECT));
        self::assertSame(self::SHOP_NEXT_NUMBER, $this->storedNextNumber(self::SHOP_PROJECT));
    }

    /**
     * The container's Tickets gives a new id, so the source knows no facts for it and no turn row is asserted.
     */
    #[Test]
    public function theContainersTicketCommandsCreatesTheTicket(): void
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        $key = $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->createdShopTicket($ticketCommands));

        self::assertSame('SHOP-'.self::SHOP_NEXT_NUMBER, $key);
        self::assertSame([['key' => $key, 'step_id' => self::TO_DO_STEP]], $this->storedTickets());
        self::assertCount(1, $this->outboxBodiesOf(TicketCreated::class));
    }

    private function createdShopTicket(TicketCommands $ticketCommands): string
    {
        return $ticketCommands->create(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::MIA),
            self::SHOP_PROJECT,
            self::TITLE,
            'The total adds the shipping twice.',
            'bug',
            'major',
            new UserId(self::OMAR),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        );
    }

    private function createTicket(): CreateTicket
    {
        $turnProjector = self::getContainer()->get('test.insight_turn_projector');
        self::assertInstanceOf(TurnProjector::class, $turnProjector);

        return new CreateTicket(
            new OrganizationProjects(new DoctrineProjects($this->entityManager())),
            self::getContainer()->get(StepQueries::class),
            new DbalNextTicketNumber($this->connection()),
            $this->ticketsWithTheFixedId(),
            new DbalTicketHistory($this->connection()),
            $turnProjector,
            new MessengerTicketEvents(self::getContainer()->get('event.bus'), $this->connection()),
        );
    }

    private function ticketsWithTheFixedId(): Tickets
    {
        return new readonly class(new DoctrineTickets($this->entityManager()), new TicketId(self::TICKET)) implements Tickets {
            public function __construct(private Tickets $doctrineTickets, private TicketId $fixedId)
            {
            }

            public function nextIdentity(): TicketId
            {
                return $this->fixedId;
            }

            public function add(Ticket $ticket): void
            {
                $this->doctrineTickets->add($ticket);
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedTickets(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT key, step_id FROM tkt_ticket WHERE organization_id = ? ORDER BY key',
            [self::ACME_FOODS],
        ));
    }

    /**
     * @return list<array<string, mixed>> at in UTC
     */
    private function storedCreatedRows(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT type, to_step, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS at FROM tkt_ticket_event WHERE organization_id = ?",
            [self::ACME_FOODS],
        ));
    }

    private function storedTurnRows(): int
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->integerOf(
            'SELECT count(*) FROM ins_ticket_turn WHERE organization_id = ?',
            [self::ACME_FOODS],
        ));
    }

    private function storedNextNumber(string $projectId): int
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->integerOf(
            'SELECT next_number FROM tkt_project WHERE organization_id = ? AND id = ?',
            [self::ACME_FOODS, $projectId],
        ));
    }

    private function outboxRows(): int
    {
        return $this->integerOf("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'", []);
    }

    /**
     * @param class-string $eventClass
     *
     * @return list<string> the body of each outbox row of that event class, oldest first
     */
    private function outboxBodiesOf(string $eventClass): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' ORDER BY id") as $body) {
            self::assertIsString($body);
            // The transport stores the serialized envelope with its quotes and backslashes escaped.
            if (str_contains(str_replace('\\', '', $body), '"'.str_replace('\\', '', $eventClass).'"')) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }

    /**
     * @param list<string> $parameters
     */
    private function integerOf(string $sql, array $parameters): int
    {
        $integer = $this->connection()->fetchOne($sql, $parameters);
        self::assertIsInt($integer);

        return $integer;
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->workflowRows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    private function turnSource(): InMemoryTicketTurnSource
    {
        $source = self::getContainer()->get(InMemoryTicketTurnSource::class);
        self::assertInstanceOf(InMemoryTicketTurnSource::class, $source);

        return $source;
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

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function workflowRows(): WorkflowRows
    {
        return new WorkflowRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
