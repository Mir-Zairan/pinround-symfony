<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketTurnSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TicketTurnQueries on a ticket TicketCommands creates, as pinround_app under row-level security, with
 * Organization's and Identity's real queries behind it.
 */
final class DbalTicketTurnQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000a721';
    // Lena's id is above Omar's, so a list in user id order differs from the order the team was built in.
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a759';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a752';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a753';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000000a754';
    private const array MORE_PEOPLE = [
        '0197a3c0-0000-7000-8000-00000000a761' => 'developer',
        '0197a3c0-0000-7000-8000-00000000a762' => 'developer',
        '0197a3c0-0000-7000-8000-00000000a763' => 'tester',
        '0197a3c0-0000-7000-8000-00000000a764' => 'tester',
    ];

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
            self::RAJ => ['Raj', 'viewer', 'other'],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aNewTicketForOmarGivesItsStepItsColumnsAndItsTeamsLeadAndPeople(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);

        $snapshot = $this->factsAs(self::ACME_FOODS, $ticketId);

        self::assertNotNull($snapshot);
        self::assertSame(
            [
                $ticketId->toString(), self::SHOP_PROJECT, ShopTeamRows::TO_DO_STEP, 'todo', 'todo', self::SHOP_TEAM,
                self::LENA, 'Lena', [self::OMAR, self::LENA], [self::MIA], 'all', 'major', null, self::MIA, self::OMAR,
                null, null, null, null, null, null, false,
            ],
            [
                $snapshot->ticketId->toString(), $snapshot->projectId, $snapshot->stepId, $snapshot->coreCode, $snapshot->groupCode,
                $snapshot->teamId?->toString(), $snapshot->leadId?->toString(), $snapshot->leadDisplayName,
                self::idsOf($snapshot->developerIds), self::idsOf($snapshot->testerIds), $snapshot->visibility, $snapshot->priority,
                $snapshot->release, $snapshot->reporterId->toString(), $snapshot->assigneeId?->toString(),
                $snapshot->pendingAssigneeId, $snapshot->reviewerId, $snapshot->testerId, $snapshot->waitingOnUserId,
                $snapshot->waitingOnLabel, $snapshot->previousStepId, $snapshot->isRiskCheck,
            ],
        );
    }

    #[Test]
    public function aRiskAmongTheCheckReasonsMakesItARiskCheck(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $this->updateTicket("UPDATE tkt_ticket SET check_reasons = '{new_area,risk}' WHERE id = ?", $ticketId);

        self::assertTrue($this->factsAs(self::ACME_FOODS, $ticketId)?->isRiskCheck);
    }

    #[Test]
    public function aTicketNoTeamOwnsHasNoLeadNoPeopleAndItsGroupWithoutACoreCode(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $this->updateTicket('UPDATE tkt_ticket SET team_id = NULL WHERE id = ?', $ticketId);

        $snapshot = $this->factsAs(self::ACME_FOODS, $ticketId);

        self::assertNotNull($snapshot);
        self::assertSame(
            [null, null, null, [], [], 'todo', null],
            [$snapshot->teamId, $snapshot->leadId, $snapshot->leadDisplayName, $snapshot->developerIds, $snapshot->testerIds, $snapshot->groupCode, $snapshot->coreCode],
        );
    }

    #[Test]
    public function anotherOrganizationFindsNoTicketWithTheId(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);

        self::assertNotNull($this->factsAs(self::ACME_FOODS, $ticketId));
        self::assertNull($this->factsAs(self::BETA_BAKERY, $ticketId));
    }

    #[Test]
    public function aLeadWithNoUserIsAnError(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $this->shopTeamRows()->addMember(self::ACME_FOODS, self::SHOP_TEAM, '0197a3c0-0000-7000-8000-00000000a765', 'member', 'developer');
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE org_team SET lead_user_id = '0197a3c0-0000-7000-8000-00000000a765' WHERE id = ?",
            [self::SHOP_TEAM],
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The lead of a ticket\'s team is no user.');
        $this->factsAs(self::ACME_FOODS, $ticketId);
    }

    #[Test]
    public function withNoTenantItRefusesBeforeAnyQuery(): void
    {
        $debugData = $this->debugData();
        $debugData->reset();

        try {
            $this->turnQueries()->factsOf(new TicketId(self::SHOP_PROJECT));
            self::fail('The ticket was read with no tenant.');
        } catch (TenantNotSet) {
            self::assertSame(0, self::queryCountOf($debugData));
        }
    }

    /**
     * The team's steps are cached, so the first read warms the cache and the second is counted.
     */
    #[Test]
    public function itCostsTheSameQueriesForABiggerTeam(): void
    {
        $ticketId = $this->createdTicketFor(self::OMAR);
        $queriesForFourPeople = $this->queriesOfFactsAs(self::ACME_FOODS, $ticketId);
        foreach (self::MORE_PEOPLE as $userId => $job) {
            $this->shopTeamRows()->addMember(self::ACME_FOODS, self::SHOP_TEAM, $userId, 'member', $job);
        }

        $queriesForEightPeople = $this->queriesOfFactsAs(self::ACME_FOODS, $ticketId);

        self::assertSame([4, 4], [$queriesForFourPeople, $queriesForEightPeople]);
        self::assertCount(4, $this->factsAs(self::ACME_FOODS, $ticketId)->developerIds ?? []);
    }

    private function createdTicketFor(string $assigneeId): TicketId
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);
        $key = $this->transactions()->committedAs(self::ACME_FOODS, static fn (): string => $ticketCommands->create(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::MIA),
            self::SHOP_PROJECT,
            'Cart total shows the wrong sum',
            '',
            'bug',
            'major',
            new UserId($assigneeId),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        ));
        $ticketId = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT id FROM tkt_ticket WHERE key = ?', [$key]));
        self::assertIsString($ticketId);

        return new TicketId($ticketId);
    }

    private function updateTicket(string $sql, TicketId $ticketId): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement($sql, [$ticketId->toString()]));
    }

    private function factsAs(string $organizationId, TicketId $ticketId): ?TicketTurnSnapshot
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): ?TicketTurnSnapshot => $this->turnQueries()->factsOf($ticketId));
    }

    private function queriesOfFactsAs(string $organizationId, TicketId $ticketId): int
    {
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs($organizationId, function () use ($debugData, $ticketId): int {
            $this->turnQueries()->factsOf($ticketId);
            $debugData->reset();
            $this->turnQueries()->factsOf($ticketId);

            return self::queryCountOf($debugData);
        });
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

    /**
     * @param list<UserId> $userIds
     *
     * @return list<string>
     */
    private static function idsOf(array $userIds): array
    {
        return array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
    }

    private function turnQueries(): TicketTurnQueries
    {
        $turnQueries = self::getContainer()->get('test.ticketing_ticket_turn_queries');
        self::assertInstanceOf(TicketTurnQueries::class, $turnQueries);

        return $turnQueries;
    }

    private function debugData(): DebugDataHolder
    {
        return self::getContainer()->get('doctrine.debug_data_holder');
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::RAJ], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
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
