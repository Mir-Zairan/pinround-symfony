<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Web\Domain\HomeFocus;
use App\Web\Infrastructure\Doctrine\DbalHomeFocusPreferences;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalHomeFocusPreferences as pinround_app on committed web_home_focus rows, in a transaction under a tenant, as
 * inside a request.
 */
final class DbalHomeFocusPreferencesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000008a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000008b1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000008c1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000008c2';

    protected function setUp(): void
    {
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        $this->removeRowsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aRememberedFocusIsFoundInTheSameOrganization(): void
    {
        $this->rememberAs(self::ACME_FOODS, self::LENA, HomeFocus::MyTeam, '2027-03-15 10:00:00 UTC');

        self::assertSame(HomeFocus::MyTeam, $this->focusAs(self::ACME_FOODS, self::LENA));
        self::assertNull($this->focusAs(self::ACME_FOODS, self::OMAR));
        self::assertSame(
            [['organization_id' => self::ACME_FOODS, 'user_id' => self::LENA, 'focus' => 'my_team', 'updated_at' => '2027-03-15 10:00:00+00']],
            $this->storedRowsOf(self::ACME_FOODS),
        );
    }

    #[Test]
    public function aSecondChoiceReplacesTheFirstInOneRow(): void
    {
        $this->rememberAs(self::ACME_FOODS, self::LENA, HomeFocus::MyTeam, '2027-03-15 10:00:00 UTC');
        $this->rememberAs(self::ACME_FOODS, self::LENA, HomeFocus::Admin, '2027-03-15 11:30:00 UTC');

        self::assertSame(HomeFocus::Admin, $this->focusAs(self::ACME_FOODS, self::LENA));
        self::assertSame(
            [['organization_id' => self::ACME_FOODS, 'user_id' => self::LENA, 'focus' => 'admin', 'updated_at' => '2027-03-15 11:30:00+00']],
            $this->storedRowsOf(self::ACME_FOODS),
        );
    }

    #[Test]
    public function eachOrganizationKeepsItsOwnFocusAndSeesNoneOfTheOther(): void
    {
        $this->rememberAs(self::ACME_FOODS, self::LENA, HomeFocus::Admin, '2027-03-15 10:00:00 UTC');

        self::assertNull($this->focusAs(self::BETA_BAKERY, self::LENA));

        $this->rememberAs(self::BETA_BAKERY, self::LENA, HomeFocus::MyWork, '2027-03-15 10:05:00 UTC');

        self::assertSame(HomeFocus::Admin, $this->focusAs(self::ACME_FOODS, self::LENA));
        self::assertSame(HomeFocus::MyWork, $this->focusAs(self::BETA_BAKERY, self::LENA));
        self::assertSame([], $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): array => $this->connection()->fetchFirstColumn('SELECT focus FROM web_home_focus WHERE organization_id = ?', [self::BETA_BAKERY]),
        ));
    }

    #[Test]
    public function withoutATenantNothingIsFoundAndNothingIsSaved(): void
    {
        $this->rememberAs(self::ACME_FOODS, self::LENA, HomeFocus::MyTeam, '2027-03-15 10:00:00 UTC');

        self::assertNull($this->preferences()->focusOf(new UserId(self::LENA)));
        try {
            $this->connection()->transactional(fn (): null => $this->preferences()->remember(new UserId(self::OMAR), HomeFocus::MyWork, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
            self::fail('A focus was saved without a tenant.');
        } catch (TenantNotSet) {
        }

        self::assertSame([self::LENA], array_column($this->storedRowsOf(self::ACME_FOODS), 'user_id'));
    }

    #[Test]
    public function rememberOutsideATransactionIsRefused(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->preferences()->remember(new UserId(self::LENA), HomeFocus::MyTeam, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
            self::fail('A focus was saved outside a transaction.');
        } catch (\LogicException $exception) {
            self::assertNotInstanceOf(TenantNotSet::class, $exception);
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame([], $this->storedRowsOf(self::ACME_FOODS));
    }

    private function rememberAs(string $organizationId, string $userId, HomeFocus $focus, string $at): void
    {
        $this->transactions()->committedAs($organizationId, fn (): null => $this->preferences()->remember(new UserId($userId), $focus, new \DateTimeImmutable($at)));
    }

    private function focusAs(string $organizationId, string $userId): ?HomeFocus
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): ?HomeFocus => $this->preferences()->focusOf(new UserId($userId)));
    }

    /**
     * @return list<array<string, mixed>> every row of the organization, read through the migrator
     */
    private function storedRowsOf(string $organizationId): array
    {
        return $this->asMigratorUnder($organizationId, static fn (Connection $migrator): array => $migrator->fetchAllAssociative(
            "SELECT organization_id, user_id, focus, to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS updated_at"
            .' FROM web_home_focus WHERE organization_id = ? ORDER BY user_id',
            [$organizationId],
        ));
    }

    private function removeRowsOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->asMigratorUnder($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM web_home_focus WHERE organization_id = ?',
                [$organizationId],
            ));
        }
    }

    /**
     * The migrator owns the table, but FORCE ROW LEVEL SECURITY binds it too, so it works under the organization.
     *
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function asMigratorUnder(string $organizationId, \Closure $work): mixed
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            return self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function preferences(): DbalHomeFocusPreferences
    {
        return new DbalHomeFocusPreferences($this->connection(), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
