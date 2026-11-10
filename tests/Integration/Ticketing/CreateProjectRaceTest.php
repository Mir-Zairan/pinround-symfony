<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Organization\Api\MemberJob;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Application\CreateProject\CreateProject;
use App\Ticketing\Application\CreateProject\ProjectKeyTaken;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectKeyInUse;
use App\Ticketing\Domain\Projects;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CreateProject losing the race for a key: a second PostgreSQL session saves SHOP and commits right after
 * CreateProject's byKey() found none, so only the unique index can refuse the second SHOP.
 */
final class CreateProjectRaceTest extends KernelTestCase
{
    public const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000014a1';
    public const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000014c1';
    public const string WINNING_PROJECT = '0197a3c0-0000-7000-8000-0000000014d1';

    private ?EntityManagerInterface $secondSession = null;

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
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
    public function aKeyCommittedByAnotherSessionAfterTheCheckIsRefusedAsProjectKeyTaken(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext())->entityManager;
        $this->secondSession = $secondSession;
        $projects = new DoctrineProjects($this->entityManager());
        $createProject = new CreateProject(
            $this->projectsThatLoseTheRaceTo($secondSession, $projects),
            self::teamQueriesWithTheShopTeam(),
        );
        $connection = $this->connection();

        $connection->beginTransaction();
        try {
            $createProject(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), 'SHOP', 'Shop');
            self::fail('CreateProject saved a second project with the key SHOP.');
        } catch (ProjectKeyTaken $keyTaken) {
            self::assertSame('Another project already uses the key SHOP.', $keyTaken->getMessage());
            self::assertInstanceOf(ProjectKeyInUse::class, $keyTaken->getPrevious());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }

        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame(
            [self::WINNING_PROJECT],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
                "SELECT id FROM tkt_project WHERE key = 'SHOP'",
            )),
        );
    }

    /**
     * Finds what the real repository finds, then lets the second session save SHOP and commit before the caller acts.
     */
    private function projectsThatLoseTheRaceTo(EntityManagerInterface $secondSession, Projects $projects): Projects
    {
        return new readonly class($secondSession, $projects) implements Projects {
            public function __construct(private EntityManagerInterface $secondSession, private Projects $projects)
            {
            }

            public function nextIdentity(): ProjectId
            {
                return $this->projects->nextIdentity();
            }

            public function byId(ProjectId $id): ?Project
            {
                return $this->projects->byId($id);
            }

            public function byKey(ProjectKey $key): ?Project
            {
                $found = $this->projects->byKey($key);
                $this->secondSession->wrapInTransaction(fn () => new DoctrineProjects($this->secondSession)->add(new Project(
                    new ProjectId(CreateProjectRaceTest::WINNING_PROJECT),
                    new OrganizationId(CreateProjectRaceTest::ACME_FOODS),
                    new TeamId(CreateProjectRaceTest::SHOP_TEAM),
                    $key,
                    'Shop',
                )));

                return $found;
            }

            public function add(Project $project): void
            {
                $this->projects->add($project);
            }
        };
    }

    private static function teamQueriesWithTheShopTeam(): TeamQueries
    {
        return new readonly class implements TeamQueries {
            public function teamsOf(OrganizationId $organizationId): array
            {
                return [new TeamId(CreateProjectRaceTest::SHOP_TEAM)];
            }

            public function summariesOf(OrganizationId $organizationId, array $teamIds): array
            {
                return $this->allSummariesOf($organizationId);
            }

            public function allSummariesOf(OrganizationId $organizationId): array
            {
                return [new TeamSummary(new TeamId(CreateProjectRaceTest::SHOP_TEAM), 'Shop', 'shop')];
            }

            public function countOf(OrganizationId $organizationId): int
            {
                return 1;
            }

            public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
            {
                throw new \LogicException('CreateProject reads no team by slug.');
            }

            public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
            {
                throw new \LogicException('CreateProject reads no jobs.');
            }
        };
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
