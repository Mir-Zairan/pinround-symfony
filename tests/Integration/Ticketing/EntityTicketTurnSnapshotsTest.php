<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
use App\Ticketing\Domain\WaitingOn;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TicketTurnSnapshots on a ticket loaded and moved as a move would, against the snapshot
 * TicketTurnQueries::factsOf reads back from the saved row, as pinround_app under row-level security.
 */
final class EntityTicketTurnSnapshotsTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000d702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000d711';
    private const string UNKNOWN_TEAM = '0197a3c0-0000-7000-8000-00000000d712';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000d721';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000d751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000d752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000d753';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000d754';
    private const string CODE_REVIEW_STEP = '0197a3c0-0000-7000-8000-00000000d741';
    private const string NEEDS_INPUT_STEP = '0197a3c0-0000-7000-8000-00000000d742';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-00000000d743';
    private const string UNKNOWN_STEP = '0197a3c0-0000-7000-8000-00000000d744';
    private const string MOVED_AT = '2027-03-16 09:30:00+00:00';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'viewer', 'other'],
        ]);
        $this->addCoreSteps();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string|null, string, bool, WaitingOn|null, bool, string, string|null, string}>
     */
    public static function moves(): iterable
    {
        // The step moved to (null: none), its group, whether it is Needs input, who it waits on, whether the ticket has
        // the turn columns a create leaves empty, then the step, core code and group code the snapshot must name.
        yield 'To do' => [null, '', false, null, false, ShopTeamRows::TO_DO_STEP, 'todo', 'todo'];
        yield 'Code review with every turn column' => [self::CODE_REVIEW_STEP, 'check', false, null, true, self::CODE_REVIEW_STEP, 'review', 'check'];
        yield 'Done' => [self::DONE_STEP, 'done', false, null, true, self::DONE_STEP, 'done', 'done'];
        yield 'Needs input waiting on a person' => [self::NEEDS_INPUT_STEP, 'wait', true, WaitingOn::ofPerson(new UserId(self::RAJ)), false, self::NEEDS_INPUT_STEP, 'input', 'wait'];
        yield 'Needs input waiting on a label' => [self::NEEDS_INPUT_STEP, 'wait', true, WaitingOn::ofLabel('Customer'), false, self::NEEDS_INPUT_STEP, 'input', 'wait'];
    }

    #[Test]
    #[DataProvider('moves')]
    public function theSnapshotOfTheMovedTicketEqualsTheOneReadFromItsSavedRow(
        ?string $stepId,
        string $groupCode,
        bool $isNeedsInputStep,
        ?WaitingOn $waitingOn,
        bool $hasTurnColumns,
        string $expectedStepId,
        ?string $expectedCoreCode,
        string $expectedGroupCode,
    ): void {
        $key = $this->createdTicketKey();
        if ($hasTurnColumns) {
            $this->setTurnColumnsOf($key);
        }

        [$fromEntity, $fromTeamLists, $fromRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key, $stepId, $groupCode, $isNeedsInputStep, $waitingOn): array {
            $ticket = $this->loaded($key);
            if (null !== $stepId) {
                $ticket->move($stepId, $groupCode, $isNeedsInputStep, $waitingOn, new \DateTimeImmutable(self::MOVED_AT));
            }
            $fromEntity = $this->snapshots()->of($ticket);
            $fromTeamLists = $this->snapshots()->ofTeamTicket($ticket, $this->stepQueries()->stepsFor(new TeamId(self::SHOP_TEAM)), $this->teamPeopleQueries()->namedPossibleAssigneesOf(new TeamId(self::SHOP_TEAM)));
            $this->entityManager()->flush();

            return [$fromEntity, $fromTeamLists, $this->turnQueries()->factsOf($ticket->id())];
        });

        self::assertEquals($fromRow, $fromEntity);
        self::assertEquals($fromRow, $fromTeamLists);
        self::assertSame(
            [$expectedStepId, $expectedCoreCode, $expectedGroupCode, self::SHOP_TEAM, self::LENA, 'Lena', [self::LENA, self::OMAR], [self::MIA]],
            [
                $fromEntity->stepId, $fromEntity->coreCode, $fromEntity->groupCode, $fromEntity->teamId?->toString(), $fromEntity->leadId?->toString(),
                $fromEntity->leadDisplayName, self::idsOf($fromEntity->developerIds), self::idsOf($fromEntity->testerIds),
            ],
        );
        self::assertSame(
            $hasTurnColumns ? [self::RAJ, self::LENA, self::MIA, '2027.3', true] : [null, null, null, null, false],
            [
                $fromEntity->pendingAssigneeId?->toString(), $fromEntity->reviewerId?->toString(), $fromEntity->testerId?->toString(),
                $fromEntity->release, $fromEntity->isRiskCheck,
            ],
        );
        self::assertSame(
            [$waitingOn?->userId()?->toString(), $waitingOn?->label(), null === $stepId ? null : ShopTeamRows::TO_DO_STEP],
            [$fromEntity->waitingOnUserId?->toString(), $fromEntity->waitingOnLabel, $fromEntity->previousStepId],
        );
    }

    #[Test]
    public function aMoveToDoneSavesWhenTheTicketClosedAndTheStepItLeft(): void
    {
        $key = $this->createdTicketKey();

        $savedRow = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key): array|false {
            $ticket = $this->loaded($key);
            $ticket->move(self::DONE_STEP, 'done', false, null, new \DateTimeImmutable(self::MOVED_AT));
            $this->entityManager()->flush();

            return $this->connection()->fetchAssociative('SELECT closed_at, updated_at, previous_step_id FROM tkt_ticket WHERE key = ?', [$key]);
        });

        self::assertIsArray($savedRow);
        self::assertIsString($savedRow['closed_at']);
        self::assertIsString($savedRow['updated_at']);
        self::assertSame(
            [self::MOVED_AT, self::MOVED_AT, ShopTeamRows::TO_DO_STEP],
            [self::utcTimeOf($savedRow['closed_at']), self::utcTimeOf($savedRow['updated_at']), $savedRow['previous_step_id']],
        );
    }

    /**
     * The step and team people caches warmed by a first snapshot serve the snapshot of the moved ticket.
     */
    #[Test]
    public function loadingByKeyRunsOneQueryAndAWarmSnapshotOfTheMovedTicketRunsNone(): void
    {
        $key = $this->createdTicketKey();
        $debugData = $this->debugData();

        $queryCounts = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $key): array {
            $debugData->reset();
            $ticket = $this->loaded($key);
            $loadQueries = self::queryCountOf($debugData);
            $this->snapshots()->of($ticket);
            $ticket->move(self::CODE_REVIEW_STEP, 'check', false, null, new \DateTimeImmutable(self::MOVED_AT));
            $debugData->reset();
            $snapshot = $this->snapshots()->of($ticket);

            return [$loadQueries, self::queryCountOf($debugData), $snapshot->coreCode];
        });

        self::assertSame([1, 0, 'review'], $queryCounts);
    }

    /**
     * A ticket no team owns gets its group from StepQueries::groupOf, no core code and no people.
     */
    #[Test]
    public function aTicketNoTeamOwnsHasTheSameSnapshotAsItsSavedRow(): void
    {
        $key = $this->createdTicketKey();
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET team_id = NULL WHERE key = ?',
            [$key],
        ));

        [$fromEntity, $fromRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key): array {
            $ticket = $this->loaded($key);

            return [$this->snapshots()->of($ticket), $this->turnQueries()->factsOf($ticket->id())];
        });

        self::assertEquals($fromRow, $fromEntity);
        self::assertSame(
            [ShopTeamRows::TO_DO_STEP, null, 'todo', null, null, [], []],
            [$fromEntity->stepId, $fromEntity->coreCode, $fromEntity->groupCode, $fromEntity->teamId, $fromEntity->leadId, $fromEntity->developerIds, $fromEntity->testerIds],
        );
    }

    #[Test]
    public function aTicketNoTeamOwnsGetsNoSnapshotFromTeamStepsAndPeople(): void
    {
        $key = $this->createdTicketKey();
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'UPDATE tkt_ticket SET team_id = NULL WHERE key = ?',
            [$key],
        ));

        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key): ?string {
            $teamId = new TeamId(self::SHOP_TEAM);
            try {
                $this->snapshots()->ofTeamTicket($this->loaded($key), $this->stepQueries()->stepsFor($teamId), $this->teamPeopleQueries()->namedPossibleAssigneesOf($teamId));
            } catch (\LogicException $noTeam) {
                return $noTeam->getMessage();
            }

            return null;
        });

        self::assertSame('A snapshot from team steps and people is built only for a ticket a team owns.', $refusal);
    }

    #[Test]
    public function aStepTheOrganizationDoesNotHaveIsRefused(): void
    {
        $key = $this->createdTicketKey();

        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key): ?string {
            $ticket = $this->loaded($key);
            $ticket->move(self::UNKNOWN_STEP, 'doing', false, null, new \DateTimeImmutable(self::MOVED_AT));

            return $this->refusalOf($ticket);
        });

        self::assertSame('A ticket is in a step its organization does not have.', $refusal);
    }

    #[Test]
    public function aTeamThatIsNoTeamOfTheOrganizationIsRefused(): void
    {
        $key = $this->createdTicketKey();

        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key): ?string {
            $this->connection()->executeStatement('UPDATE tkt_ticket SET team_id = ? WHERE key = ?', [self::UNKNOWN_TEAM, $key]);

            return $this->refusalOf($this->loaded($key));
        });

        self::assertSame('A ticket\'s team has no lead or member Identity knows, or is not a team of its organization.', $refusal);
    }

    #[Test]
    public function aTicketOfAnotherOrganizationIsRefused(): void
    {
        $acmeTicket = Ticket::report(
            new TicketId('0197a3c0-0000-7000-8000-00000000d731'),
            new OrganizationId(self::ACME_FOODS),
            new ProjectId(self::SHOP_PROJECT),
            new TicketKey(new ProjectKey('SHOP'), 1),
            'Cart total shows the wrong sum',
            '',
            TicketType::Bug,
            Priority::Major,
            Visibility::All,
            new UserId(self::MIA),
            new UserId(self::OMAR),
            new TeamId(self::SHOP_TEAM),
            ShopTeamRows::BACKLOG_STEP,
            ShopTeamRows::TO_DO_STEP,
            new \DateTimeImmutable('2027-03-15 10:00:00+00:00'),
        );

        $refusal = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?string => $this->refusalOf($acmeTicket));

        self::assertSame('A ticket\'s turn snapshot is built under its own organization as tenant.', $refusal);
    }

    #[Test]
    public function withNoTransactionItRefuses(): void
    {
        $key = $this->createdTicketKey();
        $ticket = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): Ticket => $this->loaded($key));
        self::assertFalse($this->connection()->isTransactionActive());

        self::assertSame('A ticket\'s turn snapshot is built in a transaction under the organization as tenant; none is open.', $this->refusalOf($ticket));
    }

    private function refusalOf(Ticket $ticket): ?string
    {
        try {
            $this->snapshots()->of($ticket);

            return null;
        } catch (\LogicException $refusal) {
            return $refusal->getMessage();
        }
    }

    private function loaded(string $key): Ticket
    {
        return new DoctrineTickets($this->entityManager())->ofKey(new OrganizationId(self::ACME_FOODS), TicketKey::fromString($key))
            ?? throw new \LogicException(\sprintf('The ticket %s was not found.', $key));
    }

    private static function utcTimeOf(string $timestamp): string
    {
        return new \DateTimeImmutable($timestamp)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    private static function queryCountOf(DebugDataHolder $debugData): int
    {
        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    private function createdTicketKey(): string
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);
        $ticketId = new ShopTickets($ticketCommands, $this->transactions(), $this->connection())
            ->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $key = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId->toString()]));
        self::assertIsString($key);

        return $key;
    }

    private function setTurnColumnsOf(string $key): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE tkt_ticket SET pending_assignee_id = ?, reviewer_id = ?, tester_id = ?, release = '2027.3', check_reasons = '{risk}' WHERE key = ?",
            [self::RAJ, self::LENA, self::MIA, $key],
        ));
    }

    private function addCoreSteps(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $migrator->transactional(static function (Connection $connection): void {
                foreach ([
                    [self::CODE_REVIEW_STEP, 'Code review', 'check', 'review', 2],
                    [self::NEEDS_INPUT_STEP, 'Needs input', 'wait', 'input', 3],
                    [self::DONE_STEP, 'Done', 'done', 'done', 4],
                ] as [$stepId, $name, $groupCode, $coreCode, $position]) {
                    $connection->executeStatement(
                        "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                        [self::ACME_FOODS, $stepId, $name, $groupCode, $coreCode, $position],
                    );
                }
            });
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * @param list<UserId> $userIds
     *
     * @return list<string>
     */
    private static function idsOf(array $userIds): array
    {
        return array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
    }

    private function snapshots(): TicketTurnSnapshots
    {
        $snapshots = self::getContainer()->get('test.ticketing_ticket_turn_snapshots');
        self::assertInstanceOf(TicketTurnSnapshots::class, $snapshots);

        return $snapshots;
    }

    private function stepQueries(): StepQueries
    {
        return self::getContainer()->get(StepQueries::class);
    }

    private function teamPeopleQueries(): TeamPeopleQueries
    {
        return self::getContainer()->get(TeamPeopleQueries::class);
    }

    private function turnQueries(): TicketTurnQueries
    {
        $turnQueries = self::getContainer()->get('test.ticketing_ticket_turn_queries');
        self::assertInstanceOf(TicketTurnQueries::class, $turnQueries);

        return $turnQueries;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
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
}
