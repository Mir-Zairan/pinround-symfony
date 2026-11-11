<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Ticketing\Application\FixedOpenTicketCounts;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\UnlinkProject\UnlinkProject;
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
 * A lead unlinking a project while a ticket is being saved in it on another PostgreSQL session. The open-ticket check
 * only holds if no ticket can take a number between that check and the unlink, so the ticket's attempt there must wait
 * on the project's row (lock_timeout ends that wait here, as PHP cannot wait in two places).
 */
final class UnlinkProjectRaceTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000028a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000028c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000028d1';

    private ?EntityManagerInterface $secondSession = null;

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
        $this->secondSession?->getConnection()->close();
        $this->secondSession = null;
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function aTicketNumberTakenWhileTheProjectIsBeingUnlinkedWaitsForTheUnlink(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->secondSession = $secondSession->entityManager;
        $ticketNumbers = new DbalNextTicketNumber($secondSession->entityManager->getConnection());
        /** @var \ArrayObject<int, string> $sqlStatesOfTicketMeanwhile */
        $sqlStatesOfTicketMeanwhile = new \ArrayObject();
        $leadsProjects = new ProjectsInterruptedBeforeSave(
            new DoctrineProjects($this->entityManager()),
            static function () use ($secondSession, $ticketNumbers, $sqlStatesOfTicketMeanwhile): void {
                $sqlStatesOfTicketMeanwhile[] = $secondSession->sqlStateOfFailedAttempt(static function () use ($ticketNumbers): void {
                    $ticketNumbers->takeFor(new ProjectId(self::SHOP_PROJECT));
                });
            },
        );
        $unlinkProject = new UnlinkProject($leadsProjects, new OrganizationProjects($leadsProjects), new FixedOpenTicketCounts());

        $this->connection()->transactional(static fn () => $unlinkProject(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), self::SHOP_PROJECT));

        self::assertSame(
            [SecondSession::LOCK_NOT_AVAILABLE],
            $sqlStatesOfTicketMeanwhile->getArrayCopy(),
            'A ticket took a number between the open-ticket check and the unlink.',
        );
        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame(
            [null, 1],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_values((array) $this->connection()->fetchAssociative(
                'SELECT team_id, next_number FROM tkt_project WHERE id = ?',
                [self::SHOP_PROJECT],
            ))),
        );
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
