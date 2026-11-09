<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectKeyInUse;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineProjects on the real tkt_project table as pinround_app, under row-level security.
 */
final class DoctrineProjectsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000010a1';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000010a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000010c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-0000000010c2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000010d1';
    private const string SECOND_PROJECT = '0197a3c0-0000-7000-8000-0000000010d2';
    private const string OTHER_PROJECT = '0197a3c0-0000-7000-8000-0000000010d3';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function aProjectIsSavedAndFoundByItsKeyAndId(): void
    {
        [$byKey, $byId, $storedRow] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->projects()->add(new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), '  Shop  '));
            $this->entityManager()->clear();

            return [
                $this->projects()->byKey(new ProjectKey('SHOP')),
                $this->projects()->byId(new ProjectId(self::SHOP_PROJECT)),
                $this->connection()->fetchAssociative('SELECT organization_id, team_id, key, name, next_number FROM tkt_project WHERE id = ?', [self::SHOP_PROJECT]),
            ];
        });

        self::assertNotNull($byKey);
        self::assertSame(
            [self::SHOP_PROJECT, self::ACME_FOODS, self::SHOP_TEAM, 'SHOP', 'Shop'],
            [$byKey->id()->toString(), $byKey->organizationId()->toString(), $byKey->owningTeam()->toString(), $byKey->key()->toString(), $byKey->name()],
        );
        self::assertNotNull($byId);
        self::assertSame(self::SHOP_PROJECT, $byId->id()->toString());
        self::assertSame(['organization_id' => self::ACME_FOODS, 'team_id' => self::SHOP_TEAM, 'key' => 'SHOP', 'name' => 'Shop', 'next_number' => 1], $storedRow);
    }

    #[Test]
    public function anUnknownKeyOrIdFindsNoProject(): void
    {
        [$byKey, $byId] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->projects()->add(new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop'));
            $this->entityManager()->clear();

            return [$this->projects()->byKey(new ProjectKey('SHOPX')), $this->projects()->byId(new ProjectId(self::SECOND_PROJECT))];
        });

        self::assertSame([null, null], [$byKey, $byId]);
    }

    #[Test]
    public function aSecondProjectWithTheSameKeyInTheOrganizationIsRefused(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->projects()->add(
            new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop'),
        ));

        $this->expectException(ProjectKeyInUse::class);
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->projects()->add(
            new Project(new ProjectId(self::SECOND_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::OTHER_TEAM), new ProjectKey('SHOP'), 'Shop again'),
        ));
    }

    #[Test]
    public function aSecondProjectWithTheSameIdIsNotReportedAsAKeyInUse(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->projects()->add(
            new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop'),
        ));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn () => $this->projects()->add(
            new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('CAFE'), 'Cafe'),
        ));
    }

    #[Test]
    public function anotherOrganizationMayUseTheSameKey(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->projects()->add(
            new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop'),
        ));
        $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn () => $this->projects()->add(
            new Project(new ProjectId(self::OTHER_PROJECT), new OrganizationId(self::OTHER_ORGANIZATION), new TeamId(self::OTHER_TEAM), new ProjectKey('SHOP'), 'Shop'),
        ));

        $found = $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn (): ?Project => $this->projects()->byKey(new ProjectKey('SHOP')));

        self::assertNotNull($found);
        self::assertSame(self::OTHER_PROJECT, $found->id()->toString());
    }

    #[Test]
    public function aNewIdentityIsAProjectIdNotHandedOutBefore(): void
    {
        self::assertFalse($this->projects()->nextIdentity()->equals($this->projects()->nextIdentity()));
    }

    private function projects(): DoctrineProjects
    {
        return new DoctrineProjects($this->entityManager());
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

    private function rows(): ProjectRows
    {
        return new ProjectRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
