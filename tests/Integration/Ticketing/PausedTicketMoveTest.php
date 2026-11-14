<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\LockedTicketPauses;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * TicketCommands::move and moveTo on a ticket an open question pauses, on the real tables and policy, as pinround_app
 * under row-level security. Acme Foods' SHOP-7 starts in To do, reported by Mia and assigned to Omar, who may start it.
 */
final class PausedTicketMoveTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a601';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a611';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000a621';
    private const string IN_PROGRESS_STEP = '0197a3c0-0000-7000-8000-00000000a641';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000a651';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a652';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000a653';
    private const string KEY = 'SHOP-7';
    private const string MOVED_AT = '2027-03-16 09:30:00+00:00';

    private TicketId $ticketId;

    protected function setUp(): void
    {
        self::mockTime(self::MOVED_AT);
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'In progress', 'doing', 'progress', 'core', 0)",
                [self::ACME_FOODS, self::IN_PROGRESS_STEP],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
        $shopTickets = new ShopTickets($this->ticketCommands(), $this->transactions(), $this->connection());
        $this->ticketId = $shopTickets->createdFor(self::ACME_FOODS, self::SHOP_PROJECT, self::MIA, self::OMAR);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aVerbOnAPausedTicketIsRefusedWithThePauseNoteAndTheTicketStaysPut(): void
    {
        $this->pause();
        $before = $this->stepAndVersion();

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            self::KEY,
            'start_work',
            $before['version'],
            new \DateTimeImmutable(self::MOVED_AT),
        )));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertSame('Paused until {asked} answers the question below.', $refusal->ruleNoteTemplate);
        self::assertSame(['asked' => self::LENA], array_map(static fn (UserId $userId): string => $userId->toString(), $refusal->ruleNotePeople));
        self::assertSame($before, $this->stepAndVersion());
    }

    #[Test]
    public function aMoveToAStepOnAPausedTicketIsRefusedWithThePauseNoteAndTheTicketStaysPut(): void
    {
        $this->pause();
        $before = $this->stepAndVersion();

        $refusal = $this->refusalOf(fn () => $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->moveTo(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::LENA),
            self::KEY,
            self::IN_PROGRESS_STEP,
            $before['version'],
            null,
            null,
            new \DateTimeImmutable(self::MOVED_AT),
        )));

        self::assertInstanceOf(MoveRefused::class, $refusal);
        self::assertSame('Paused until {asked} answers the question below.', $refusal->ruleNoteTemplate);
        self::assertSame(['asked' => self::LENA], array_map(static fn (UserId $userId): string => $userId->toString(), $refusal->ruleNotePeople));
        self::assertSame($before, $this->stepAndVersion());
    }

    #[Test]
    public function theSameVerbMovesTheTicketOnceTheAnswerResumesIt(): void
    {
        $this->pause();
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->pauses()->resumeAfterAnswer($this->ticketId));
        self::getContainer()->get('doctrine')->resetManager();
        $before = $this->stepAndVersion();

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->ticketCommands()->move(
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            self::KEY,
            'start_work',
            $before['version'],
            new \DateTimeImmutable(self::MOVED_AT),
        ));

        self::assertSame(['step_id' => self::IN_PROGRESS_STEP, 'version' => $before['version'] + 1], $this->stepAndVersion());
    }

    private function pause(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->pauses()->pauseForQuestion($this->ticketId, new UserId(self::LENA), new UserId(self::OMAR)));
        self::getContainer()->get('doctrine')->resetManager();
    }

    /**
     * @return array{step_id: string, version: int}
     */
    private function stepAndVersion(): array
    {
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative('SELECT step_id, version FROM tkt_ticket WHERE key = ?', [self::KEY]));
        self::assertIsArray($row);
        self::assertIsString($row['step_id']);
        self::assertIsInt($row['version']);

        return ['step_id' => $row['step_id'], 'version' => $row['version']];
    }

    private function refusalOf(\Closure $work): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $refusal) {
            self::getContainer()->get('doctrine')->resetManager();

            return $refusal;
        }

        return null;
    }

    private function ticketTurnSnapshots(): TicketTurnSnapshots
    {
        $ticketTurnSnapshots = self::getContainer()->get('test.ticketing_ticket_turn_snapshots');
        self::assertInstanceOf(TicketTurnSnapshots::class, $ticketTurnSnapshots);

        return $ticketTurnSnapshots;
    }

    private function pauses(): LockedTicketPauses
    {
        return new LockedTicketPauses(new DoctrineTickets($this->entityManager()), $this->ticketTurnSnapshots(), $this->tenantContext());
    }

    private function ticketCommands(): TicketCommands
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return $ticketCommands;
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS);
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
