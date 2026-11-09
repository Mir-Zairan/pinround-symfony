<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\UnknownProject;
use App\Ticketing\Infrastructure\Doctrine\DbalNextTicketNumber;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A project of one organization is out of reach under another organization as tenant, for reads, writes and ticket
 * numbers alike, with the tenant filter on and off, and its counter does not move.
 */
final class ProjectIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000012a1';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000012a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000012c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000012d1';
    private const string SMUGGLED_PROJECT = '0197a3c0-0000-7000-8000-0000000012d2';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => new DoctrineProjects($this->entityManager())->add(
            new Project(new ProjectId(self::SHOP_PROJECT), new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM), new ProjectKey('SHOP'), 'Shop'),
        ));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function anotherOrganizationFindsTheProjectNeitherByKeyNorById(): void
    {
        $projects = new DoctrineProjects($this->entityManager());

        [$byKey, $byId] = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, static fn (): array => [
            $projects->byKey(new ProjectKey('SHOP')),
            $projects->byId(new ProjectId(self::SHOP_PROJECT)),
        ]);
        $ownByKey = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): ?Project => $projects->byKey(new ProjectKey('SHOP')));

        self::assertSame([null, null], [$byKey, $byId]);
        self::assertNotNull($ownByKey, 'The project is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function rowLevelSecurityAloneHidesTheProjectFromAnotherOrganizationWithTheTenantFilterOff(): void
    {
        $projects = new DoctrineProjects($this->entityManager());
        $numbers = new DbalNextTicketNumber($this->connection());
        $this->entityManager()->clear();
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        [$byKey, $byId] = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, static fn (): array => [
            $projects->byKey(new ProjectKey('SHOP')),
            $projects->byId(new ProjectId(self::SHOP_PROJECT)),
        ]);
        try {
            $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, static fn (): int => $numbers->takeFor(new ProjectId(self::SHOP_PROJECT)));
            self::fail('Another organization took a number of the project with the tenant filter off.');
        } catch (UnknownProject) {
        }
        $ownById = $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): ?Project => $projects->byId(new ProjectId(self::SHOP_PROJECT)));

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertSame([null, null], [$byKey, $byId]);
        self::assertNotNull($ownById, 'The project is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function anotherOrganizationCannotAddAProjectInTheNameOfTheOrganization(): void
    {
        try {
            $this->asOtherOrganization(\sprintf(
                "INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number) VALUES ('%s', '%s', '%s', 'SMUG', 'Smuggled', 1)",
                self::ACME_FOODS,
                self::SMUGGLED_PROJECT,
                self::SHOP_TEAM,
            ));
            self::fail('Another organization added a project in the name of Acme Foods.');
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString('new row violates row-level security policy for table "tkt_project"', $refusal->getMessage());
        }

        self::assertSame(['SHOP'], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT key FROM tkt_project')));
    }

    #[Test]
    public function anotherOrganizationChangesNoRowOfTheProject(): void
    {
        $changedRows = $this->asOtherOrganization(\sprintf(
            "UPDATE tkt_project SET next_number = next_number + 100, name = 'Taken' WHERE organization_id = '%s'",
            self::ACME_FOODS,
        ));

        self::assertSame(0, $changedRows);
        self::assertSame(
            ['name' => 'Shop', 'next_number' => 1],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative('SELECT name, next_number FROM tkt_project WHERE id = ?', [self::SHOP_PROJECT])),
        );
    }

    #[Test]
    public function anotherOrganizationCannotTakeANumberOfTheProject(): void
    {
        $numbers = new DbalNextTicketNumber($this->connection());

        try {
            $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, static fn (): int => $numbers->takeFor(new ProjectId(self::SHOP_PROJECT)));
            self::fail('Another organization took a number of the project.');
        } catch (UnknownProject) {
        }

        self::assertSame(1, $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): int => $numbers->takeFor(new ProjectId(self::SHOP_PROJECT))));
    }

    #[Test]
    public function takingANumberOutsideATransactionIsRefusedAndTakesNone(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            new DbalNextTicketNumber($this->connection())->takeFor(new ProjectId(self::SHOP_PROJECT));
            self::fail('A number was taken outside a transaction.');
        } catch (\LogicException) {
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }

        $numbers = new DbalNextTicketNumber($this->connection());
        self::assertSame(1, $this->transactions()->rolledBackAs(self::ACME_FOODS, static fn (): int => $numbers->takeFor(new ProjectId(self::SHOP_PROJECT))));
    }

    private function asOtherOrganization(string $sql): int
    {
        return (int) $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement($sql));
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
