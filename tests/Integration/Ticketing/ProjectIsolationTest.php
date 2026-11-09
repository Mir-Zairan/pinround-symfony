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
use App\Ticketing\Domain\UnknownProject;
use App\Ticketing\Infrastructure\Doctrine\DbalNextTicketNumber;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A project of one organization is out of reach under another organization as tenant, for reads and for ticket
 * numbers alike, and its counter does not move.
 */
final class ProjectIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000012a1';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000012a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000012c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000012d1';

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
