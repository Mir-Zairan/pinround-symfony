<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Insight\Api\TurnProjector;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketAlreadyPaused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPauses;
use App\Ticketing\Application\LockedTicketPauses;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * TicketPauses on the real tables and turn projector, as pinround_app under row-level security. Acme Foods' SHOP-7
 * starts in To do, reported by Mia and assigned to Omar; Beta Bakery has a SHOP-7 of its own, which no pause may reach.
 */
final class TicketPausesTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000a502';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a511';
    private const string BETA_TEAM = '0197a3c0-0000-7000-8000-00000000a512';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000a521';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000a522';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a551';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a552';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a553';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a555';

    private TicketId $ticketId;
    private TicketId $betaTicketId;

    protected function setUp(): void
    {
        self::mockTime('2027-03-16 09:30:00+00:00');
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::BETA_TEAM, self::BETA_PROJECT, self::SARA, [self::SARA => ['Sara', 'lead', 'developer']]);
        $shopTickets = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection());
        $this->ticketId = $shopTickets->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
        $this->betaTicketId = $shopTickets->createdFor(self::BETA_BAKERY, self::BETA_PROJECT, self::SARA, self::SARA);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aPausedTicketGivesTheTurnToTheAskedPersonAndKeepsItsStepAndListPlace(): void
    {
        $before = $this->pauseState($this->ticketId, self::ACME_FOODS);
        self::assertIsInt($before['version']);
        self::assertSame(['turn_user_id' => self::OMAR], array_intersect_key($this->turnOf($this->ticketId), ['turn_user_id' => true]));
        self::mockTime('2027-03-16 11:45:00+00:00');

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::LENA), new UserId(self::OMAR));
            $this->turnProjector()->refresh($this->ticketId);
        });

        self::assertSame(
            ['asked_user_id' => self::LENA, 'asker_id' => self::OMAR, 'step_id' => $before['step_id'], 'version' => $before['version'] + 1, 'updated_at' => $before['updated_at']],
            $this->pauseState($this->ticketId, self::ACME_FOODS),
        );
        self::assertSame(['turn_user_id' => self::LENA, 'kind' => 'answer'], $this->turnOf($this->ticketId));
    }

    #[Test]
    public function resumingGivesTheTurnBackToTheStepsNormalOwner(): void
    {
        $turnBefore = $this->turnOf($this->ticketId);
        self::assertSame(self::OMAR, $turnBefore['turn_user_id'] ?? null);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::LENA), new UserId(self::OMAR));
            $this->turnProjector()->refresh($this->ticketId);
        });
        self::getContainer()->get('doctrine')->resetManager();

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->resumeAfterAnswer($this->ticketId);
            $this->turnProjector()->refresh($this->ticketId);
        });

        self::assertSame([null, null], array_values(array_intersect_key(
            $this->pauseState($this->ticketId, self::ACME_FOODS),
            ['asked_user_id' => true, 'asker_id' => true],
        )));
        self::assertSame($turnBefore, $this->turnOf($this->ticketId));
    }

    #[Test]
    public function aSecondQuestionIsRefusedAndTheFirstPauseStays(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::LENA), new UserId(self::OMAR));
        });
        self::getContainer()->get('doctrine')->resetManager();
        $paused = $this->pauseState($this->ticketId, self::ACME_FOODS);

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::MIA), new UserId(self::LENA));
        }));

        self::assertInstanceOf(TicketAlreadyPaused::class, $refusal);
        self::assertSame('This ticket already waits for an answer.', $refusal->getMessage());
        self::assertSame(['asked_user_id' => self::LENA, 'asker_id' => self::OMAR], array_intersect_key($paused, ['asked_user_id' => true, 'asker_id' => true]));
        self::assertSame($paused, $this->pauseState($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aPauseOfATicketReadBeforeAnotherRequestPausedItIsRefusedAndTheOtherPauseStays(): void
    {
        $before = $this->pauseState($this->ticketId, self::ACME_FOODS);
        self::assertIsInt($before['version']);

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, function () use ($before): void {
            $loaded = $this->entityManager()->find(Ticket::class, ['organizationId' => self::ACME_FOODS, 'id' => $this->ticketId->toString()]);
            self::assertNotNull($loaded);

            // A second connection commits the other request's pause, with the version raise its save would write.
            self::assertSame(1, $this->migrator()->transactional(fn (Connection $concurrent): int|string => $concurrent->executeStatement(
                'UPDATE tkt_ticket SET asked_user_id = ?, asker_id = ?, version = version + 1 WHERE organization_id = ? AND id = ? AND version = ?',
                [self::MIA, self::LENA, self::ACME_FOODS, $this->ticketId->toString(), $before['version']],
            )));

            $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::LENA), new UserId(self::OMAR));
        }));

        self::assertInstanceOf(TicketChangedMeanwhile::class, $refusal);
        self::assertSame('The ticket was changed after it was read.', $refusal->getMessage());
        self::assertSame(
            ['asked_user_id' => self::MIA, 'asker_id' => self::LENA, 'step_id' => $before['step_id'], 'version' => $before['version'] + 1, 'updated_at' => $before['updated_at']],
            $this->pauseState($this->ticketId, self::ACME_FOODS),
        );
    }

    #[Test]
    public function anotherOrganizationsTicketIsNotFoundAndStaysUnpaused(): void
    {
        $betaBefore = $this->pauseState($this->betaTicketId, self::BETA_BAKERY);

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->pauseForQuestion($this->betaTicketId, new UserId(self::LENA), new UserId(self::OMAR));
        }));

        self::assertInstanceOf(TicketNotFound::class, $refusal);
        self::assertSame(['asked_user_id' => null, 'asker_id' => null], array_intersect_key($betaBefore, ['asked_user_id' => true, 'asker_id' => true]));
        self::assertSame($betaBefore, $this->pauseState($this->betaTicketId, self::BETA_BAKERY));
    }

    #[Test]
    public function resumingATicketThatWaitsForNoAnswerIsRefusedAndNothingIsWritten(): void
    {
        $before = $this->pauseState($this->ticketId, self::ACME_FOODS);

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->pauses()->resumeAfterAnswer($this->ticketId);
        }));

        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertSame('This ticket waits for no answer.', $refusal->getMessage());
        self::assertSame($before, $this->pauseState($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aPauseOutsideATransactionIsRefusedBeforeAnyWrite(): void
    {
        $before = $this->pauseState($this->ticketId, self::ACME_FOODS);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        try {
            $refusal = $this->refusalOf(fn () => $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::LENA), new UserId(self::OMAR)));
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertSame('A ticket is locked only inside a transaction.', $refusal->getMessage());
        self::assertSame($before, $this->pauseState($this->ticketId, self::ACME_FOODS));
    }

    #[Test]
    public function aLockedTicketsRowStaysLockedUntilTheTransactionEnds(): void
    {
        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): ?\Throwable {
            $ticket = new DoctrineTickets($this->entityManager())->lockedOf(new OrganizationId(self::ACME_FOODS), $this->ticketId);
            self::assertNotNull($ticket);

            // A second connection in its own transaction, under the same tenant, stands in for a concurrent request.
            return $this->refusalOf(fn () => $this->migrator()->transactional(fn (Connection $concurrent): array => $concurrent->fetchFirstColumn(
                'SELECT id FROM tkt_ticket WHERE organization_id = ? AND id = ? FOR UPDATE NOWAIT',
                [self::ACME_FOODS, $this->ticketId->toString()],
            )));
        });

        // 55P03 is PostgreSQL's lock_not_available, which NOWAIT raises on a row another transaction holds.
        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame('55P03', $refusal->getSQLState());
    }

    #[Test]
    public function aTicketTheRequestLoadedBeforeIsLockedAsTheSameObject(): void
    {
        [$isSameObject, $refusal] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $loaded = $this->entityManager()->find(Ticket::class, ['organizationId' => self::ACME_FOODS, 'id' => $this->ticketId->toString()]);
            self::assertNotNull($loaded);
            $locked = new DoctrineTickets($this->entityManager())->lockedOf(new OrganizationId(self::ACME_FOODS), $this->ticketId);

            return [$loaded === $locked, $this->refusalOf(fn () => $this->migrator()->transactional(fn (Connection $concurrent): array => $concurrent->fetchFirstColumn(
                'SELECT id FROM tkt_ticket WHERE organization_id = ? AND id = ? FOR UPDATE NOWAIT',
                [self::ACME_FOODS, $this->ticketId->toString()],
            )))];
        });

        self::assertTrue($isSameObject);
        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame('55P03', $refusal->getSQLState());
    }

    /**
     * @return array<string, mixed> asked_user_id, asker_id, step_id, version and updated_at of the ticket
     */
    private function pauseState(TicketId $ticketId, string $organizationId): array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchAssociative(
            'SELECT asked_user_id, asker_id, step_id, version, updated_at FROM tkt_ticket WHERE id = ?',
            [$ticketId->toString()],
        ));
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed> the turn_user_id and kind of the ticket's turn row; empty when it has none
     */
    private function turnOf(TicketId $ticketId): array
    {
        $turn = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative(
            'SELECT turn_user_id, kind FROM ins_ticket_turn WHERE ticket_id = ?',
            [$ticketId->toString()],
        ));

        return false === $turn ? [] : $turn;
    }

    private function refusalOf(\Closure $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        return null;
    }

    private function pauses(): TicketPauses
    {
        return new LockedTicketPauses(new DoctrineTickets($this->entityManager()), $this->tenantContext());
    }

    private function turnProjector(): TurnProjector
    {
        $turnProjector = self::getContainer()->get('test.insight_turn_projector');
        self::assertInstanceOf(TurnProjector::class, $turnProjector);

        return $turnProjector;
    }

    private function ticketCommands(): TicketCommands
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return $ticketCommands;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::SARA], self::ACME_FOODS, self::BETA_BAKERY);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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
