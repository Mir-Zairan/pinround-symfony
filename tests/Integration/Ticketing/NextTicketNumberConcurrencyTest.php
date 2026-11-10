<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Infrastructure\Doctrine\DbalNextTicketNumber;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalNextTicketNumber used by several PostgreSQL sessions at once, as parallel requests would. PHP cannot wait in two
 * places, so instead of 50 processes five sessions take turns: while one holds its number's row lock open, the next
 * one's attempt must wait (lock_timeout ends that wait here, checked every fifth turn) and only gets a number after the
 * holder commits or rolls back. Some holders roll back, and their number must go to the next taker.
 */
final class NextTicketNumberConcurrencyTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000011a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000011c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000011d1';
    private const int SESSION_COUNT = 5;
    private const int COMMITTED_TICKET_COUNT = 50;

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => new DoctrineProjects($this->entityManager())->add(
            new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop'),
        ));
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        $this->otherSessions = [];
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function fiftyCommittedTicketsFromTakingTurnSessionsGetTheNumbersOneToFiftyEachOnce(): void
    {
        $sessions = [];
        for ($i = 0; $i < self::SESSION_COUNT; ++$i) {
            $sessions[] = $this->secondSession();
        }

        $committedNumbers = [];
        $turn = 0;
        while (\count($committedNumbers) < self::COMMITTED_TICKET_COUNT) {
            $holder = $sessions[$turn % self::SESSION_COUNT];
            $waiter = $sessions[($turn + 1) % self::SESSION_COUNT];
            $holderConnection = $holder->entityManager->getConnection();
            $waiterNumbers = self::numbersOf($waiter->entityManager->getConnection());

            $holderConnection->beginTransaction();
            $number = self::numbersOf($holderConnection)->takeFor(new ProjectId(self::SHOP_PROJECT));
            // Each probe waits out lock_timeout (300ms), so every fifth turn is probed, not all of them.
            if (0 === $turn % 5) {
                self::assertSame(
                    SecondSession::LOCK_NOT_AVAILABLE,
                    $waiter->sqlStateOfFailedAttempt(static function () use ($waiterNumbers): void {
                        $waiterNumbers->takeFor(new ProjectId(self::SHOP_PROJECT));
                    }),
                    'A second session took a number while the first one held the project.',
                );
            }

            // Every seventh holder gives its number back, as a ticket that failed to save would.
            if (6 === $turn % 7) {
                $holderConnection->rollBack();
            } else {
                $holderConnection->commit();
                $committedNumbers[] = $number;
            }
            ++$turn;
        }

        self::assertSame(range(1, self::COMMITTED_TICKET_COUNT), $committedNumbers);
        self::assertSame(self::COMMITTED_TICKET_COUNT + 1, $this->connection()->transactional(static fn (Connection $connection): mixed => $connection->fetchOne(
            'SELECT next_number FROM tkt_project WHERE id = ?',
            [self::SHOP_PROJECT],
        )));
    }

    private static function numbersOf(Connection $connection): DbalNextTicketNumber
    {
        return new DbalNextTicketNumber($connection);
    }

    private function secondSession(): SecondSession
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;

        return $secondSession;
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

    private function rows(): ProjectRows
    {
        return new ProjectRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
