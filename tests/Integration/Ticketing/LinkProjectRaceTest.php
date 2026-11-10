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
use App\Ticketing\Application\LinkProject\LinkProject;
use App\Ticketing\Application\LinkProject\ProjectStillLinked;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\OrganizationTeams;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Projects;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two leads linking the same unlinked project to their own teams at once, each on its own PostgreSQL session. PHP
 * cannot wait in two places, so the second lead tries between the first one's check and its write, where it must
 * wait on the project's row (lock_timeout ends that wait here), and tries again once the first one has committed.
 */
final class LinkProjectRaceTest extends KernelTestCase
{
    public const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000027a1';
    public const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000027c1';
    public const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-0000000027c2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000027d1';

    private ?EntityManagerInterface $secondSession = null;

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $shop = new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop');
            $shop->unlink();
            new DoctrineProjects($this->entityManager())->add($shop);
        });
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
    public function theSecondLeadWaitsForTheFirstAndIsThenRefusedAsProjectStillLinked(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->secondSession = $secondSession->entityManager;
        $secondLeadLinks = static function () use ($secondSession): void {
            self::linkProject(new DoctrineProjects($secondSession->entityManager))(
                new OrganizationId(self::ACME_FOODS),
                new TeamId(self::MOBILE_TEAM),
                self::SHOP_PROJECT,
            );
        };
        /** @var \ArrayObject<int, string> $sqlStatesOfSecondLeadMeanwhile */
        $sqlStatesOfSecondLeadMeanwhile = new \ArrayObject();
        $firstLeadsProjects = new ProjectsInterruptedBeforeSave(
            new DoctrineProjects($this->entityManager()),
            static function () use ($secondSession, $secondLeadLinks, $sqlStatesOfSecondLeadMeanwhile): void {
                $sqlStatesOfSecondLeadMeanwhile[] = $secondSession->sqlStateOfFailedAttempt($secondLeadLinks);
            },
        );

        $this->connection()->transactional(static function () use ($firstLeadsProjects): void {
            self::linkProject($firstLeadsProjects)(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), self::SHOP_PROJECT);
        });

        self::assertSame(
            [SecondSession::LOCK_NOT_AVAILABLE],
            $sqlStatesOfSecondLeadMeanwhile->getArrayCopy(),
            'The second lead got past the check while the first one held the project.',
        );
        $secondConnection = $secondSession->entityManager->getConnection();
        $secondConnection->beginTransaction();
        try {
            $secondLeadLinks();
            self::fail('The second lead linked a project the first one had linked already.');
        } catch (ProjectStillLinked $stillLinked) {
            self::assertSame('That project is already linked to a team.', $stillLinked->getMessage());
        } finally {
            $secondConnection->rollBack();
        }
        self::getContainer()->get('doctrine')->resetManager();
        self::assertSame(
            self::SHOP_TEAM,
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne(
                'SELECT team_id FROM tkt_project WHERE id = ?',
                [self::SHOP_PROJECT],
            )),
        );
    }

    private static function linkProject(Projects $projects): LinkProject
    {
        return new LinkProject($projects, new OrganizationProjects($projects), new OrganizationTeams(self::teamQueriesWithTwoTeams()));
    }

    private static function teamQueriesWithTwoTeams(): TeamQueries
    {
        return new readonly class implements TeamQueries {
            public function teamsOf(OrganizationId $organizationId): array
            {
                return [new TeamId(LinkProjectRaceTest::SHOP_TEAM), new TeamId(LinkProjectRaceTest::MOBILE_TEAM)];
            }

            public function summariesOf(OrganizationId $organizationId, array $teamIds): array
            {
                throw new \LogicException('LinkProject reads no team summaries.');
            }

            public function allSummariesOf(OrganizationId $organizationId): array
            {
                throw new \LogicException('LinkProject reads no team summaries.');
            }

            public function countOf(OrganizationId $organizationId): int
            {
                throw new \LogicException('LinkProject counts no teams.');
            }

            public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary
            {
                throw new \LogicException('LinkProject reads no team by slug.');
            }

            public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob
            {
                throw new \LogicException('LinkProject reads no jobs.');
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
