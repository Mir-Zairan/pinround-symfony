<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tkt_project table as Version20261110100000 creates it: who may do what, its indexes, row-level security and
 * the checks the database keeps on its own.
 */
final class ProjectTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000013a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000013c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000013d1';
    private const string CHECK_VIOLATION = '23514';

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * The app reads, adds and changes projects and never removes one; support reads under the tenant policy.
     */
    #[Test]
    public function theAppMayReadAddAndChangeButNotRemoveProjectsAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
                 FROM pg_class c, aclexplode(c.relacl) acl
                 WHERE c.oid = 'public.tkt_project'::regclass AND acl.grantee <> c.relowner",
            ),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = 'public.tkt_project'::regclass"));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.tkt_project'::regclass",
        ));
    }

    #[Test]
    public function everyIndexStartsWithTheOrganizationAndTheKeyIsUniquePerOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX tkt_project_pkey ON public.tkt_project USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX tkt_project_organization_id_key_key ON public.tkt_project USING btree (organization_id, key)',
                'CREATE INDEX tkt_project_organization_id_team_id_idx ON public.tkt_project USING btree (organization_id, team_id)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'tkt_project'"),
        );
    }

    #[Test]
    public function rowLevelSecurityIsOnAndForced(): void
    {
        self::assertSame(
            ['relrowsecurity' => true, 'relforcerowsecurity' => true],
            $this->connection()->fetchAssociative("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'public.tkt_project'::regclass"),
        );
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function rowsTheDatabaseRefuses(): iterable
    {
        yield 'a key in lowercase' => ['shop', 1, 'tkt_project_key_check'];
        yield 'a key starting with a digit' => ['1SHOP', 1, 'tkt_project_key_check'];
        yield 'a one-letter key' => ['S', 1, 'tkt_project_key_check'];
        yield 'a key of eleven characters' => ['SHOPSHOPSHO', 1, 'tkt_project_key_check'];
        yield 'a key with a hyphen' => ['SH-OP', 1, 'tkt_project_key_check'];
        yield 'a next number of zero' => ['SHOP', 0, 'tkt_project_next_number_check'];
    }

    #[Test]
    #[DataProvider('rowsTheDatabaseRefuses')]
    public function theDatabaseRefusesABadKeyOrNextNumber(string $key, int $nextNumber, string $violatedCheck): void
    {
        try {
            $this->insertedAsTheApp($key, $nextNumber);
            self::fail('The database accepted the row.');
        } catch (DriverException $refusal) {
            // DBAL has no own exception for a check violation; PostgreSQL names the check only in the message.
            self::assertSame([self::CHECK_VIOLATION, true], [$refusal->getSQLState(), str_contains($refusal->getMessage(), $violatedCheck)]);
        }
    }

    #[Test]
    public function theDatabaseAcceptsTheLongestAndShortestKeys(): void
    {
        self::assertSame([1, 1], [$this->insertedAsTheApp('S1', 1), $this->insertedAsTheApp('SHOPSHOPSH', 1)]);
    }

    /**
     * @return int|string the rows inserted, in a transaction that is rolled back
     */
    private function insertedAsTheApp(string $key, int $nextNumber): int|string
    {
        $connection = $this->connection();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $connection->beginTransaction();
        try {
            return $connection->executeStatement(
                'INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number) VALUES (?, ?, ?, ?, ?, ?)',
                [self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, $key, 'Shop', $nextNumber],
            );
        } finally {
            $connection->rollBack();
            $tenantContext->clear();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
