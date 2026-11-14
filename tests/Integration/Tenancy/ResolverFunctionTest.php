<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\Migration\ResolverFunctions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the resolver functions (data-model.md › Lookups before the tenant is known): owned by
 * pinround_resolver, SECURITY DEFINER with a fixed search_path, ids only, executable by pinround_app
 * only; and that TenantTable::allowResolverRead lets such a function read across organizations, but
 * only the granted columns.
 */
final class ResolverFunctionTest extends KernelTestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';
    private const string UNKNOWN_ID = '0197a3c0-0000-7000-8000-0000000000ff';

    public static function setUpBeforeClass(): void
    {
        self::probeTable()->recreate();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::probeTable()->drop();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    /** A call with a key no row has, per resolver. */
    private const array UNKNOWN_KEY_CALLS = [
        'resolve_api_token' => "resolve_api_token(sha256('unknown token'::bytea))",
        'resolve_user_orgs' => "resolve_user_orgs('".self::UNKNOWN_ID."'::uuid)",
        'resolve_invitation' => "resolve_invitation(sha256('unknown invite'::bytea))",
        'resolve_sso_provider' => "resolve_sso_provider('https://login.acme-foods.example', 'acme-foods.example')",
        'resolve_jira_connection' => "resolve_jira_connection('".self::UNKNOWN_ID."'::uuid)",
        'resolve_laptop_package' => "resolve_laptop_package('".self::UNKNOWN_ID."'::uuid)",
        'search_ticket_ids' => "search_ticket_ids('".self::UNKNOWN_ID."'::uuid, 'checkout', '".self::UNKNOWN_ID."'::uuid, '{}'::uuid[], true, true, NULL, NULL, NULL)",
        // No notification is held until before 1970.
        'organizations_with_due_notifications' => "organizations_with_due_notifications('1970-01-01 00:00:00+00'::timestamptz)",
        // No notification was made an hour before 1970.
        'digest_recipients' => "digest_recipients('1970-01-01 00:00:00+00'::timestamptz)",
    ];

    private const array OUTPUT_COLUMNS = [
        'resolve_api_token' => ['token_id', 'organization_id', 'user_id'],
        'resolve_user_orgs' => ['organization_id'],
        'resolve_invitation' => ['invitation_id', 'organization_id'],
        'resolve_sso_provider' => ['provider_config_id', 'organization_id'],
        'resolve_jira_connection' => ['organization_id'],
        'resolve_laptop_package' => ['organization_id', 'user_id'],
        'search_ticket_ids' => ['ticket_id'],
        'organizations_with_due_notifications' => ['organization_id'],
        'digest_recipients' => ['organization_id', 'user_id'],
    ];

    /**
     * Keyed by ResolverFunctions::NAMES, so a listed resolver without a case here fails on a missing key.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function resolverOutputColumns(): iterable
    {
        foreach (ResolverFunctions::NAMES as $name) {
            yield $name => [$name, self::OUTPUT_COLUMNS[$name]];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resolverCallsWithAnUnknownKey(): iterable
    {
        foreach (ResolverFunctions::NAMES as $name) {
            yield $name => [$name, self::UNKNOWN_KEY_CALLS[$name]];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resolverNames(): iterable
    {
        foreach (ResolverFunctions::NAMES as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @param list<string> $outputColumns
     */
    #[Test]
    #[DataProvider('resolverOutputColumns')]
    public function itIsASecurityDefinerOwnedByTheResolverWithAFixedSearchPathReturningOnlyUuids(string $name, array $outputColumns): void
    {
        $function = $this->connection()->fetchAssociative(
            "SELECT p.proowner::regrole::text AS owner, p.prosecdef AS is_security_definer, p.proconfig::text AS config,
                    p.proretset AS returns_set, p.prokind::text AS kind
             FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$name],
        );

        self::assertSame(
            ['owner' => 'pinround_resolver', 'is_security_definer' => true, 'config' => '{"search_path=pg_catalog, public, pg_temp"}', 'returns_set' => true, 'kind' => 'f'],
            $function,
        );
        self::assertSame(
            array_map(static fn (string $column): string => $column.' uuid', $outputColumns),
            $this->connection()->fetchFirstColumn(
                "SELECT a.name || ' ' || format_type(a.type, NULL)
                 FROM pg_proc p, unnest(p.proallargtypes, p.proargmodes, p.proargnames) WITH ORDINALITY AS a(type, mode, name, position)
                 WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ? AND a.mode = 't'
                 ORDER BY a.position",
                [$name],
            ),
        );
        // A same-named column would win over an unprefixed input in the body (see the probe test below).
        $inputNames = $this->connection()->fetchFirstColumn(
            "SELECT a.name
             FROM pg_proc p, unnest(p.proallargtypes, p.proargmodes, p.proargnames) AS a(type, mode, name)
             WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ? AND a.mode = 'i'",
            [$name],
        );
        self::assertNotSame([], $inputNames);
        foreach ($inputNames as $inputName) {
            self::assertIsString($inputName);
            self::assertStringStartsWith('p_', $inputName);
        }
    }

    #[Test]
    #[DataProvider('resolverCallsWithAnUnknownKey')]
    public function theAppRoleGetsNoRowAndNoErrorForAnUnknownKey(string $name, string $unknownKeyCall): void
    {
        self::assertSame([], $this->connection()->fetchAllAssociative('SELECT * FROM '.$unknownKeyCall), $name);
    }

    #[Test]
    #[DataProvider('resolverCallsWithAnUnknownKey')]
    public function theReadonlyRoleIsRefused(string $name, string $unknownKeyCall): void
    {
        $readonly = self::getContainer()->get('doctrine.dbal.readonly_connection');
        self::assertInstanceOf(Connection::class, $readonly);
        self::assertSame('pinround_readonly', $readonly->fetchOne('SELECT current_user'));

        try {
            $readonly->fetchAllAssociative('SELECT * FROM '.$unknownKeyCall);
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
            self::assertStringContainsString(\sprintf('permission denied for function %s', $name), $exception->getMessage());

            return;
        }

        self::fail(\sprintf('pinround_readonly could execute %s.', $name));
    }

    #[Test]
    #[DataProvider('resolverNames')]
    public function onlyTheAppRoleAndTheOwnerMayExecuteItAndPublicMayNot(string $name): void
    {
        $connection = $this->connection();
        $executors = $connection->fetchFirstColumn(
            "SELECT CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE acl.grantee::regrole::text END
             FROM pg_proc p, aclexplode(p.proacl) acl
             WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ? AND acl.privilege_type = 'EXECUTE'",
            [$name],
        );

        self::assertEqualsCanonicalizing(['pinround_app', 'pinround_resolver'], $executors);
        foreach (['pinround_migrator', 'pinround_retention', 'pinround_readonly'] as $role) {
            self::assertFalse(
                $connection->fetchOne(\sprintf("SELECT has_function_privilege(?, 'public.%s'::regproc, 'EXECUTE')", $name), [$role]),
                $role,
            );
        }
        self::assertTrue($connection->fetchOne(\sprintf("SELECT has_function_privilege('pinround_app', 'public.%s'::regproc, 'EXECUTE')", $name)));
    }

    /**
     * search_ticket_ids reads tkt_ticket through resolver_read, and only the columns it matches on or returns: never
     * the description, people beyond the visibility predicate's, dates or status. Project and step are the list's own
     * filters, applied before the cap.
     */
    #[Test]
    public function theTicketSearchReadsOnlyTheColumnsItMatchesOnOrReturns(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'organization_id', 'id', 'project_id', 'step_id', 'key', 'title', 'search', 'visibility', 'team_id', 'reporter_id',
                'assignee_id', 'pending_assignee_id', 'reviewer_id', 'tester_id', 'waiting_on_user_id',
            ],
            $this->connection()->fetchFirstColumn(
                "SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl
                 WHERE a.attrelid = 'public.tkt_ticket'::regclass AND acl.grantee = 'pinround_resolver'::regrole AND acl.privilege_type = 'SELECT'",
            ),
        );
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT privilege_type FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.oid = 'public.tkt_ticket'::regclass AND acl.grantee = 'pinround_resolver'::regrole",
        ));
        self::assertSame(
            [['policyname' => 'resolver_read', 'roles' => '{pinround_resolver}', 'cmd' => 'SELECT', 'qual' => 'true']],
            $this->connection()->fetchAllAssociative(
                "SELECT policyname, roles::text AS roles, cmd, qual FROM pg_policies
                 WHERE schemaname = 'public' AND tablename = 'tkt_ticket' AND policyname <> 'tenant_isolation'",
            ),
        );
    }

    /**
     * organizations_with_due_notifications and digest_recipients read ntf_notification through resolver_read on the
     * parent only, and only the ids they return and the columns they match on: never a notification's actor or data.
     */
    #[Test]
    public function theNotificationResolversReadOnlyTheColumnsTheyMatchOnOrReturnOfTheParent(): void
    {
        $connection = $this->connection();
        self::assertEqualsCanonicalizing(
            ['organization_id', 'deliver_after', 'user_id', 'kind', 'ticket_id', 'read_at', 'done_at', 'created_at'],
            $connection->fetchFirstColumn(
                "SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl
                 WHERE a.attrelid = 'public.ntf_notification'::regclass AND acl.grantee = 'pinround_resolver'::regrole AND acl.privilege_type = 'SELECT'",
            ),
        );
        self::assertSame(
            [['policyname' => 'resolver_read', 'roles' => '{pinround_resolver}', 'cmd' => 'SELECT', 'qual' => 'true']],
            $connection->fetchAllAssociative(
                "SELECT policyname, roles::text AS roles, cmd, qual FROM pg_policies
                 WHERE schemaname = 'public' AND tablename = 'ntf_notification' AND policyname <> 'tenant_isolation'",
            ),
        );
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT c.relname || ' ' || a.attname FROM pg_inherits i
             JOIN pg_class c ON c.oid = i.inhrelid
             JOIN pg_attribute a ON a.attrelid = c.oid, aclexplode(a.attacl) acl
             WHERE i.inhparent = 'public.ntf_notification'::regclass AND acl.grantee = 'pinround_resolver'::regrole",
        ));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT c.relname || ' ' || pol.polname FROM pg_inherits i
             JOIN pg_class c ON c.oid = i.inhrelid JOIN pg_policy pol ON pol.polrelid = c.oid
             WHERE i.inhparent = 'public.ntf_notification'::regclass AND pol.polname = 'resolver_read'",
        ));
    }

    /**
     * resolver_read lets pinround_resolver read every organization's tickets, so a function of it that reads tkt_ticket
     * keeps to the transaction's tenant itself: p_organization_id must equal app.org_id, or no row matches.
     */
    #[Test]
    public function everyResolverFunctionThatReadsTicketsKeepsToTheTenantOfTheTransaction(): void
    {
        /** @var array<string, string> $bodiesByName */
        $bodiesByName = $this->connection()->fetchAllKeyValue(
            "SELECT p.proname, p.prosrc FROM pg_proc p
             WHERE p.pronamespace = 'public'::regnamespace AND p.proowner = 'pinround_resolver'::regrole AND p.prosrc ~ '\\mtkt_ticket\\M'",
        );

        self::assertContains('search_ticket_ids', array_keys($bodiesByName));
        foreach ($bodiesByName as $name => $body) {
            self::assertMatchesRegularExpression(
                "/\\bp_organization_id\\s*=\\s*NULLIF\\(\\s*current_setting\\('app\\.org_id',\\s*true\\),\\s*''\\s*\\)::uuid/",
                $body,
                $name,
            );
        }
    }

    #[Test]
    public function aResolverFindsAnotherOrganizationsRowWhileTheAppRoleSeesOnlyItsOwn(): void
    {
        $this->insertProbeRows();
        $connection = $this->connection();
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));

        [$visibleKeys, $resolvedOrgs] = $connection->transactional(static fn (Connection $connection): array => [
            $connection->fetchFirstColumn('SELECT lookup_key FROM zz_resolver_probe ORDER BY lookup_key'),
            $connection->fetchFirstColumn("SELECT organization_id FROM zz_resolve_probe('b-key')"),
        ]);

        self::assertSame(['a-key'], $visibleKeys);
        self::assertSame([self::ORG_B], $resolvedOrgs);
        $this->tenantContext()->clear();
        self::assertSame([self::ORG_B], $connection->fetchFirstColumn("SELECT organization_id FROM zz_resolve_probe('b-key')"));
    }

    #[Test]
    public function aResolverCannotReadAColumnThatWasNotGranted(): void
    {
        $this->insertProbeRows();

        try {
            $this->connection()->fetchFirstColumn("SELECT secret_note FROM zz_resolve_probe_note('b-key')");
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
            self::assertStringContainsString('permission denied for table zz_resolver_probe', $exception->getMessage());

            return;
        }

        self::fail('The resolver read a column that allowResolverRead did not grant.');
    }

    #[Test]
    public function aPrefixedInputMatchesOnlyItsKeyWhileAnInputNamedLikeTheColumnMatchesEveryRow(): void
    {
        $this->insertProbeRows();
        $connection = $this->connection();

        self::assertSame([], $connection->fetchFirstColumn("SELECT organization_id FROM zz_resolve_probe('unknown-key')"));
        self::assertSame([self::ORG_A], $connection->fetchFirstColumn("SELECT organization_id FROM zz_resolve_probe('a-key')"));
        self::assertEqualsCanonicalizing(
            [self::ORG_A, self::ORG_B],
            $connection->fetchFirstColumn("SELECT organization_id FROM zz_resolve_probe_unprefixed('unknown-key')"),
        );
    }

    #[Test]
    public function theMigratorDoesNotInheritTheResolverReadPolicy(): void
    {
        $this->insertProbeRows();
        $migrator = $this->migrator();
        $this->tenantContext()->set(new OrganizationId(self::ORG_A));

        self::assertSame(['a-key'], $migrator->transactional(
            static fn (Connection $migrator): array => $migrator->fetchFirstColumn('SELECT lookup_key FROM zz_resolver_probe'),
        ));

        $this->tenantContext()->clear();
        // The table owner obeys FORCE ROW LEVEL SECURITY; with no tenant only resolver_read could show it a row.
        self::assertSame([], $migrator->fetchFirstColumn('SELECT lookup_key FROM zz_resolver_probe'));
    }

    private static function probeTable(): ResolverProbeTable
    {
        return new ResolverProbeTable(self::getContainer()->get('doctrine.dbal.migrator_connection'));
    }

    private function insertProbeRows(): void
    {
        $this->migrator()->executeStatement('TRUNCATE zz_resolver_probe');
        foreach ([self::ORG_A => 'a-key', self::ORG_B => 'b-key'] as $organizationId => $lookupKey) {
            $this->tenantContext()->set(new OrganizationId($organizationId));
            $this->connection()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
                'INSERT INTO zz_resolver_probe (organization_id, lookup_key, secret_note) VALUES (?, ?, ?)',
                [$organizationId, $lookupKey, 'note of '.$lookupKey],
            ));
        }
        $this->tenantContext()->clear();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
