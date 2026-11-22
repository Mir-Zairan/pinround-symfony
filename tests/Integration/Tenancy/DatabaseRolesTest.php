<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Provider\OrmSchemaProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves docker/postgres/roles.sql ran and the migrations ran as pinround_migrator (ADR-0005).
 * It reads the catalog through the normal kernel connection, which must be pinround_app.
 */
final class DatabaseRolesTest extends KernelTestCase
{
    private const array DOCUMENTED_ORGANIZATION_INDEXES_NOT_LED_BY_THE_ORGANIZATION = [
        'CREATE UNIQUE INDEX org_organization_slug_key ON public.org_organization USING btree (slug)',
        'CREATE INDEX org_membership_user_id_idx ON public.org_membership USING btree (user_id)',
        'CREATE UNIQUE INDEX org_invitation_token_hash_key ON public.org_invitation USING btree (token_hash)',
    ];
    private const array ROLES = ['pinround_app', 'pinround_migrator', 'pinround_retention', 'pinround_readonly'];
    private const array MESSENGER_TABLES = ['messenger_outbox', 'messenger_failed'];

    #[Test]
    public function theKernelConnectsAsTheAppRole(): void
    {
        self::assertSame('pinround_app', $this->connection()->fetchOne('SELECT current_user'));
    }

    #[Test]
    public function exactlyTheFourPinroundRolesExistAndCanLogIn(): void
    {
        $loginRoles = $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%' AND rolcanlogin",
        );

        self::assertEqualsCanonicalizing(self::ROLES, $loginRoles);
    }

    #[Test]
    public function theResolverIsTheOnlyPinroundRoleWithoutLogin(): void
    {
        self::assertSame(['pinround_resolver'], $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%' AND NOT rolcanlogin",
        ));
    }

    #[Test]
    public function onlyTheMigratorIsAMemberOfAnyRoleAndItCanSetButNotInheritTheResolver(): void
    {
        self::assertSame(
            ['pinround_migrator in pinround_resolver inherit=false set=true admin=false'],
            $this->connection()->fetchFirstColumn(
                "SELECT m.member::regrole::text || ' in ' || m.roleid::regrole::text
                        || ' inherit=' || m.inherit_option || ' set=' || m.set_option || ' admin=' || m.admin_option
                 FROM pg_auth_members m
                 WHERE m.member::regrole::text LIKE 'pinround\\_%' OR m.roleid = 'pinround_resolver'::regrole",
            ),
        );
    }

    #[Test]
    public function theResolverHasSchemaUsageButNoCreateAndNoConnect(): void
    {
        $connection = $this->connection();

        self::assertTrue($connection->fetchOne("SELECT has_schema_privilege('pinround_resolver', 'public', 'USAGE')"));
        self::assertFalse($connection->fetchOne("SELECT has_schema_privilege('pinround_resolver', 'public', 'CREATE')"));
        self::assertFalse($connection->fetchOne("SELECT has_database_privilege('pinround_resolver', current_database(), 'CONNECT')"));
    }

    #[Test]
    public function onlyTheRetentionRoleBypassesRowLevelSecurity(): void
    {
        $bypassingRoles = $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%' AND rolbypassrls",
        );

        self::assertSame(['pinround_retention'], $bypassingRoles);
    }

    #[Test]
    public function noPinroundRoleIsSuperuserOrCreatesRolesOrDatabasesOrReplicates(): void
    {
        $privilegedRoles = $this->connection()->fetchFirstColumn(
            "SELECT rolname FROM pg_roles WHERE rolname LIKE 'pinround\\_%'
             AND (rolsuper OR rolcreaterole OR rolcreatedb OR rolreplication)",
        );

        self::assertSame([], $privilegedRoles);
    }

    #[Test]
    public function theAppRoleCannotCreateObjectsButTheMigratorCan(): void
    {
        $connection = $this->connection();

        self::assertFalse($connection->fetchOne("SELECT has_schema_privilege('pinround_app', 'public', 'CREATE')"));
        self::assertFalse($connection->fetchOne("SELECT has_database_privilege('pinround_app', current_database(), 'CREATE')"));
        self::assertFalse($connection->fetchOne("SELECT has_database_privilege('pinround_app', current_database(), 'TEMPORARY')"));
        self::assertTrue($connection->fetchOne("SELECT has_schema_privilege('pinround_migrator', 'public', 'CREATE')"));
    }

    #[Test]
    public function publicGetsNothingAndOnlyThePinroundRolesConnect(): void
    {
        $connection = $this->connection();

        self::assertSame([], $connection->fetchFirstColumn(
            'SELECT acl.privilege_type FROM pg_database d, aclexplode(d.datacl) acl
             WHERE d.datname = current_database() AND acl.grantee = 0',
        ));
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT acl.privilege_type FROM pg_namespace n, aclexplode(n.nspacl) acl
             WHERE n.nspname = 'public' AND acl.grantee = 0",
        ));
        // The owner (pinround_migrator) is listed too: an explicit ACL spells out the owner's own rights.
        self::assertEqualsCanonicalizing(self::ROLES, $connection->fetchFirstColumn(
            "SELECT acl.grantee::regrole::text FROM pg_database d, aclexplode(d.datacl) acl
             WHERE d.datname = current_database() AND acl.privilege_type = 'CONNECT'",
        ));
    }

    #[Test]
    public function noDefaultPrivilegeGivesTheAppOrReadonlyRoleRights(): void
    {
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT d.defaclobjtype::text || ' ' || acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_default_acl d, aclexplode(d.defaclacl) acl
             WHERE acl.grantee IN ('pinround_app'::regrole, 'pinround_readonly'::regrole)",
        ));
    }

    #[Test]
    public function aNewTableHasOnlyTheRightsItsMigrationGrantsAndAPartitionNone(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $migrator->beginTransaction();

        try {
            $migrator->executeStatement('CREATE TABLE roles_probe_plain (id int)');
            $migrator->executeStatement('CREATE TABLE roles_probe_parent (id int, month int) PARTITION BY LIST (month)');
            $migrator->executeStatement('CREATE TABLE roles_probe_child PARTITION OF roles_probe_parent FOR VALUES IN (1)');
            $migrator->executeStatement('GRANT SELECT, INSERT ON roles_probe_parent TO pinround_app');

            self::assertSame([], $this->grantsToOthersThanTheOwner($migrator, 'roles_probe_plain'));
            self::assertEqualsCanonicalizing(
                ['pinround_app INSERT', 'pinround_app SELECT'],
                $this->grantsToOthersThanTheOwner($migrator, 'roles_probe_parent'),
            );
            self::assertSame([], $this->grantsToOthersThanTheOwner($migrator, 'roles_probe_child'));
        } finally {
            $migrator->rollBack();
        }
    }

    /** Vacuous until the first partitioned table; then it guards every migration that adds one. */
    #[Test]
    public function noChildPartitionInTheSchemaHasDirectGrants(): void
    {
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT c.relname || ' ' || acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.relnamespace = 'public'::regnamespace AND c.relispartition AND acl.grantee <> c.relowner",
        ));
    }

    #[Test]
    public function theAppAndReadonlyRolesHoldNoTruncateReferencesOrTriggerInTheSchema(): void
    {
        self::assertSame([], $this->connection()->fetchFirstColumn(
            "SELECT c.relname || ' ' || acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.relnamespace = 'public'::regnamespace
               AND acl.grantee IN ('pinround_app'::regrole, 'pinround_readonly'::regrole)
               AND acl.privilege_type IN ('TRUNCATE', 'REFERENCES', 'TRIGGER')",
        ));
    }

    /** The app only reads which migrations ran, for /health/ready (Version20261107180000); roles.sql keeps it that way. */
    #[Test]
    public function theAppRoleMayOnlyReadTheMigrationsTableAndNobodyElseHasRights(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'doctrine_migration_versions'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.doctrine_migration_versions')"));
        self::assertGreaterThan(0, $connection->fetchOne('SELECT count(*) FROM doctrine_migration_versions'));
        self::assertFalse($connection->fetchOne("SELECT has_table_privilege('pinround_readonly', 'public.doctrine_migration_versions', 'SELECT')"));
    }

    /** The worker inserts, locks, marks and deletes rows; support reports never read envelopes (Version20261107150000). */
    #[Test]
    public function theAppRoleAloneMayReadAndWriteTheMessengerTables(): void
    {
        $connection = $this->connection();

        foreach (self::MESSENGER_TABLES as $table) {
            self::assertEqualsCanonicalizing(
                ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
                $this->grantsToOthersThanTheOwner($connection, $table),
                $table,
            );
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
        }
    }

    /** Append-only for the app, so a recorded message cannot be forgotten and handled again (Version20261107160000). */
    #[Test]
    public function theAppRoleMayOnlyReadAndAddProcessedMessages(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'processed_message'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.processed_message')"));
        // The retention purge (P11) deletes by age.
        self::assertSame(
            ['CREATE INDEX processed_message_processed_at_idx ON public.processed_message USING btree (processed_at)'],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'processed_message' AND indexname <> 'processed_message_pkey'"),
        );
    }

    /**
     * Append-only for the app; support reads it under row-level security. Grants on the parent only, so no role can
     * read a partition directly and skip the parent's policy (ADR-0026, Version20261107170000).
     */
    #[Test]
    public function theAuditParentAloneHasGrantsAndTheAppMayOnlyReadAndAdd(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'aud_event'),
        );
        /** @var list<string> $partitions */
        $partitions = $connection->fetchFirstColumn("SELECT inhrelid::regclass::text FROM pg_inherits WHERE inhparent = 'public.aud_event'::regclass");
        self::assertCount(14, $partitions);
        foreach ($partitions as $partition) {
            self::assertSame([], $this->grantsToOthersThanTheOwner($connection, $partition), $partition);
        }
    }

    /**
     * Global Identity tables: the app reads, adds and changes users, credentials, password resets and two-step logins,
     * deletes only two-step logins (turning it off), and only adds login attempts; the retention job purges attempts and
     * resets. pinround_readonly gets nothing, as no tenant policy would limit it (Version20261107190000,
     * Version20261108120000, Version20261108180000, Version20261108200000).
     */
    #[Test]
    public function theAppRoleAloneHasRightsOnTheIdentityTablesAndOnlyAddsLoginAttempts(): void
    {
        $connection = $this->connection();
        $expectedGrants = [
            'idn_user' => ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
            'idn_credential' => ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
            'idn_login_attempt' => ['pinround_app INSERT', 'pinround_app SELECT'],
            'idn_password_reset' => ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
            'idn_mfa_secret' => ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
        ];

        foreach ($expectedGrants as $table => $grants) {
            self::assertEqualsCanonicalizing($grants, $this->grantsToOthersThanTheOwner($connection, $table), $table);
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
        }
        self::assertSame(
            ['CREATE INDEX idn_login_attempt_email_hash_at_idx ON public.idn_login_attempt USING btree (email_hash, at)'],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_login_attempt' AND indexname <> 'idn_login_attempt_pkey'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX idn_password_reset_token_hash_key ON public.idn_password_reset USING btree (token_hash)',
                'CREATE INDEX idn_password_reset_user_id_idx ON public.idn_password_reset USING btree (user_id)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_password_reset' AND indexname <> 'idn_password_reset_pkey'"),
        );
        self::assertFalse($connection->fetchOne("SELECT relrowsecurity FROM pg_class WHERE oid = 'public.idn_password_reset'::regclass"));
        self::assertSame([], $connection->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_mfa_secret' AND indexname <> 'idn_mfa_secret_pkey'"));
        self::assertFalse($connection->fetchOne("SELECT relrowsecurity FROM pg_class WHERE oid = 'public.idn_mfa_secret'::regclass"));
    }

    /**
     * Tenant tables of Organization: the app reads, adds and changes all of them and removes only memberships, team
     * members and invitations; support reads under the tenant policy; resolve_user_orgs reads two columns of
     * org_membership (Version20261108140000) and resolve_invitation five of org_invitation, never the email
     * (Version20261109100000).
     */
    #[Test]
    public function theOrganizationTablesGrantTheAppNoDeleteButOnMembershipsTeamMembersAndInvitations(): void
    {
        $connection = $this->connection();
        $readAddChange = ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'];
        $expectedGrants = [
            'org_organization' => $readAddChange,
            'org_membership' => [...$readAddChange, 'pinround_app DELETE'],
            'org_team' => $readAddChange,
            'org_team_member' => [...$readAddChange, 'pinround_app DELETE'],
            'org_settings' => $readAddChange,
            'org_invitation' => [...$readAddChange, 'pinround_app DELETE'],
        ];

        foreach ($expectedGrants as $table => $grants) {
            self::assertEqualsCanonicalizing($grants, $this->grantsToOthersThanTheOwner($connection, $table), $table);
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
        }
        self::assertEqualsCanonicalizing(
            [
                'org_membership.organization_id SELECT', 'org_membership.user_id SELECT',
                'org_invitation.id SELECT', 'org_invitation.organization_id SELECT', 'org_invitation.token_hash SELECT',
                'org_invitation.accepted_at SELECT', 'org_invitation.expires_at SELECT',
            ],
            $connection->fetchFirstColumn(
                "SELECT a.attrelid::regclass::text || '.' || a.attname || ' ' || acl.privilege_type
                 FROM pg_attribute a, aclexplode(a.attacl) acl
                 WHERE acl.grantee = 'pinround_resolver'::regrole AND a.attrelid::regclass::text LIKE 'org\\_%'",
            ),
        );
    }

    /**
     * One invitation not yet accepted per email of an organization, and each link token at most once across all
     * organizations, for resolve_invitation; the team foreign key has its index (Version20261109100000).
     */
    #[Test]
    public function theInvitationTableIsKeyedByTheOrganizationAndHasOneOpenInvitationPerEmail(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX org_invitation_pkey ON public.org_invitation USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX org_invitation_token_hash_key ON public.org_invitation USING btree (token_hash)',
                'CREATE UNIQUE INDEX org_invitation_organization_id_email_key ON public.org_invitation USING btree (organization_id, email) WHERE (accepted_at IS NULL)',
                'CREATE INDEX org_invitation_organization_id_team_id_idx ON public.org_invitation USING btree (organization_id, team_id)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'org_invitation'"),
        );
    }

    /**
     * The Web tenant table: the app reads, adds and changes each user's Home focus and never removes one; support reads
     * under the tenant policy; the primary key is its only index and starts with organization_id (Version20261108160000).
     */
    #[Test]
    public function theHomeFocusTableGrantsTheAppNoDeleteAndIsKeyedByTheOrganization(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'web_home_focus'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.web_home_focus')"));
        self::assertSame(
            ['CREATE UNIQUE INDEX web_home_focus_pkey ON public.web_home_focus USING btree (organization_id, user_id)'],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'web_home_focus'"),
        );
        self::assertSame([], $connection->fetchFirstColumn(
            "SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.web_home_focus'::regclass",
        ));
    }

    /**
     * The ticket tables (Version20261111120000): the app reads, adds and changes tickets and summaries and removes
     * neither; support reads under the tenant policy. The only column rights are pinround_resolver's SELECT on the
     * tkt_ticket columns search_ticket_ids matches on, filters on or returns (Version20261112120000).
     */
    #[Test]
    public function theTicketTablesGrantTheAppNoDeleteAndSupportOnlyRead(): void
    {
        $connection = $this->connection();
        $expectedColumnGrants = [
            'tkt_ticket' => array_map(
                static fn (string $column): string => 'pinround_resolver SELECT '.$column,
                ['organization_id', 'id', 'project_id', 'step_id', 'key', 'title', 'search', 'visibility', 'team_id', 'reporter_id',
                    'assignee_id', 'pending_assignee_id', 'reviewer_id', 'tester_id', 'waiting_on_user_id'],
            ),
            'tkt_summary' => [],
        ];

        foreach ($expectedColumnGrants as $table => $columnGrants) {
            self::assertEqualsCanonicalizing(
                ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
                $this->grantsToOthersThanTheOwner($connection, $table),
                $table,
            );
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
            self::assertEqualsCanonicalizing($columnGrants, $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = to_regclass(?)",
                ['public.'.$table],
            ), $table);
        }
    }

    /**
     * Ticketing's view for Insight's one allowed join (Version20261114100000): only read, and run with the reader's
     * rights, so tkt_ticket's row-level security applies to it.
     */
    #[Test]
    public function theTicketListViewIsOnlyReadAndRunsWithTheReadersRights(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(['pinround_app SELECT', 'pinround_readonly SELECT'], $this->grantsToOthersThanTheOwner($connection, 'tkt_ticket_list_v'));
        self::assertSame(
            ['v', 'pinround_migrator', '{security_invoker=true}'],
            $connection->fetchNumeric("SELECT relkind::text, relowner::regrole::text, reloptions::text FROM pg_class WHERE oid = to_regclass('public.tkt_ticket_list_v')"),
        );
    }

    /**
     * A ticket's history (Version20261111140000): append-only for the app, read by support under the tenant policy.
     * Grants on the parent only, so no role reads a partition directly and skips the parent's policy.
     */
    #[Test]
    public function theTicketEventParentAloneHasGrantsAndTheAppMayOnlyReadAndAdd(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'tkt_ticket_event'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.tkt_ticket_event')"));
        /** @var list<string> $partitions */
        $partitions = $connection->fetchFirstColumn("SELECT inhrelid::regclass::text FROM pg_inherits WHERE inhparent = 'public.tkt_ticket_event'::regclass");
        // The migration's three months from the clock it ran at; the partition job may have added more.
        self::assertGreaterThanOrEqual(3, \count($partitions));
        foreach ($partitions as $partition) {
            self::assertMatchesRegularExpression('/^tkt_ticket_event_\d{6}$/', $partition);
            self::assertSame([], $this->grantsToOthersThanTheOwner($connection, $partition), $partition);
        }
    }

    /**
     * The thread and notification tables (Version20261114120000, Version20261114130000): messages, replies, pings and
     * notifications are changed but never removed by the app, a mute is removed to unmute, a delivery is only added;
     * support reads under the tenant policy. Grants on the ntf_notification parent only.
     */
    #[Test]
    public function theConversationAndNotificationTablesGrantTheAppOnlyWhatEachNeeds(): void
    {
        $connection = $this->connection();
        $readOnly = ['pinround_readonly SELECT'];
        $expectedGrants = [
            'cnv_message' => [...$readOnly, 'pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE'],
            'cnv_reply' => [...$readOnly, 'pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE'],
            'ntf_ping' => [...$readOnly, 'pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE'],
            'ntf_notification' => [...$readOnly, 'pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE'],
            'ntf_mute' => [...$readOnly, 'pinround_app SELECT', 'pinround_app INSERT', 'pinround_app DELETE'],
            'ntf_delivery' => [...$readOnly, 'pinround_app SELECT', 'pinround_app INSERT'],
        ];

        // The only column rights: what organizations_with_due_notifications (Version20261115140000) and digest_recipients
        // (Version20261115150000) match on and return.
        $expectedColumnGrants = ['ntf_notification' => array_map(
            static fn (string $column): string => 'pinround_resolver SELECT '.$column,
            ['organization_id', 'deliver_after', 'user_id', 'kind', 'ticket_id', 'read_at', 'done_at', 'created_at'],
        )];

        foreach ($expectedGrants as $table => $grants) {
            self::assertEqualsCanonicalizing($grants, $this->grantsToOthersThanTheOwner($connection, $table), $table);
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
            self::assertEqualsCanonicalizing($expectedColumnGrants[$table] ?? [], $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = to_regclass(?)",
                ['public.'.$table],
            ), $table);
        }

        /** @var list<string> $partitions */
        $partitions = $connection->fetchFirstColumn("SELECT inhrelid::regclass::text FROM pg_inherits WHERE inhparent = 'public.ntf_notification'::regclass ORDER BY 1");
        // A database migrated later has more months, so only the months the migration creates are pinned.
        self::assertSame(
            [
                'ntf_notification_202611', 'ntf_notification_202612', 'ntf_notification_202701', 'ntf_notification_202702',
                'ntf_notification_202703', 'ntf_notification_202704', 'ntf_notification_202705', 'ntf_notification_202706',
                'ntf_notification_202707', 'ntf_notification_202708', 'ntf_notification_202709', 'ntf_notification_202710',
                'ntf_notification_202711', 'ntf_notification_202712',
            ],
            array_values(array_filter(
                $partitions,
                static fn (string $partition): bool => $partition >= 'ntf_notification_202611' && $partition <= 'ntf_notification_202712',
            )),
        );
        foreach ($partitions as $partition) {
            self::assertMatchesRegularExpression('/^ntf_notification_\d{6}$/', $partition);
            self::assertSame([], $this->grantsToOthersThanTheOwner($connection, $partition), $partition);
        }
    }

    /**
     * Every index of the thread and notification tables starts with organization_id, with the partial predicates the
     * thread, the bell, the digest, the release after quiet hours and the rate limit read by.
     */
    #[Test]
    public function theConversationAndNotificationIndexesAllStartWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX cnv_message_pkey ON public.cnv_message USING btree (organization_id, id)',
                'CREATE INDEX cnv_message_ticket_id_created_at_id_idx ON public.cnv_message USING btree (organization_id, ticket_id, created_at, id)',
                "CREATE UNIQUE INDEX cnv_message_ticket_id_open_question_key ON public.cnv_message USING btree (organization_id, ticket_id) WHERE ((kind = 'question'::text) AND (resolved_at IS NULL) AND (removed_at IS NULL))",
                "CREATE INDEX cnv_message_to_user_id_open_question_idx ON public.cnv_message USING btree (organization_id, to_user_id) WHERE ((kind = 'question'::text) AND (resolved_at IS NULL) AND (removed_at IS NULL))",
                'CREATE UNIQUE INDEX cnv_reply_pkey ON public.cnv_reply USING btree (organization_id, id)',
                'CREATE INDEX cnv_reply_message_id_created_at_id_idx ON public.cnv_reply USING btree (organization_id, message_id, created_at, id)',
                'CREATE UNIQUE INDEX ntf_ping_pkey ON public.ntf_ping USING btree (organization_id, id)',
                'CREATE INDEX ntf_ping_from_id_to_id_ticket_id_created_at_idx ON public.ntf_ping USING btree (organization_id, from_id, to_id, ticket_id, created_at)',
                'CREATE INDEX ntf_ping_to_id_ticket_id_open_idx ON public.ntf_ping USING btree (organization_id, to_id, ticket_id) WHERE (cleared_at IS NULL)',
                'CREATE INDEX ntf_ping_ticket_id_idx ON public.ntf_ping USING btree (organization_id, ticket_id)',
                'CREATE UNIQUE INDEX ntf_notification_pkey ON ONLY public.ntf_notification USING btree (organization_id, id, created_at)',
                'CREATE INDEX ntf_notification_user_id_actor_id_ticket_id_created_at_idx ON ONLY public.ntf_notification USING btree (organization_id, user_id, actor_id, ticket_id, created_at DESC) WHERE (done_at IS NULL)',
                'CREATE INDEX ntf_notification_user_id_created_at_unread_idx ON ONLY public.ntf_notification USING btree (organization_id, user_id, created_at) WHERE (read_at IS NULL)',
                'CREATE INDEX ntf_notification_user_id_created_at_digest_idx ON ONLY public.ntf_notification USING btree (organization_id, user_id, created_at) INCLUDE (deliver_after)'
                ." WHERE ((read_at IS NULL) AND (done_at IS NULL) AND (ticket_id IS NOT NULL) AND (kind = ANY (ARRAY['ping'::text, 'question'::text])))",
                'CREATE INDEX ntf_notification_deliver_after_idx ON ONLY public.ntf_notification USING btree (organization_id, deliver_after) WHERE (deliver_after IS NOT NULL)',
                'CREATE INDEX ntf_notification_ticket_id_idx ON ONLY public.ntf_notification USING btree (organization_id, ticket_id) WHERE (ticket_id IS NOT NULL)',
                'CREATE UNIQUE INDEX ntf_mute_pkey ON public.ntf_mute USING btree (organization_id, user_id, ticket_id)',
                'CREATE INDEX ntf_mute_ticket_id_idx ON public.ntf_mute USING btree (organization_id, ticket_id)',
                'CREATE UNIQUE INDEX ntf_delivery_pkey ON public.ntf_delivery USING btree (organization_id, user_id, window_key)',
            ],
            $this->connection()->fetchFirstColumn(
                "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public'
                 AND tablename IN ('cnv_message', 'cnv_reply', 'ntf_ping', 'ntf_notification', 'ntf_mute', 'ntf_delivery')",
            ),
        );
    }

    /**
     * AI call metering (Version20261117100000): append-only for the app, read by support under the tenant policy; the
     * rows hold no content. Grants on the parent only, so no role reads a partition directly and skips the parent's
     * policy, and every index starts with the organization.
     */
    #[Test]
    public function theUsageParentAloneHasGrantsAndTheAppMayOnlyReadAndAdd(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'ast_usage'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.ast_usage')"));
        /** @var list<string> $partitions */
        $partitions = $connection->fetchFirstColumn("SELECT inhrelid::regclass::text FROM pg_inherits WHERE inhparent = 'public.ast_usage'::regclass ORDER BY 1");
        // A database migrated later has more months, so only the months the migration creates are pinned.
        self::assertSame(
            [
                'ast_usage_202611', 'ast_usage_202612', 'ast_usage_202701', 'ast_usage_202702', 'ast_usage_202703', 'ast_usage_202704',
                'ast_usage_202705', 'ast_usage_202706', 'ast_usage_202707', 'ast_usage_202708', 'ast_usage_202709', 'ast_usage_202710',
                'ast_usage_202711', 'ast_usage_202712',
            ],
            array_values(array_filter($partitions, static fn (string $partition): bool => $partition >= 'ast_usage_202611' && $partition <= 'ast_usage_202712')),
        );
        foreach ($partitions as $partition) {
            self::assertMatchesRegularExpression('/^ast_usage_\d{6}\z/', $partition);
            self::assertSame([], $this->grantsToOthersThanTheOwner($connection, $partition), $partition);
            self::assertSame(
                [true, true],
                $connection->fetchNumeric('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$partition]),
                $partition,
            );
        }
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX ast_usage_pkey ON ONLY public.ast_usage USING btree (organization_id, id, at)',
                'CREATE INDEX ast_usage_at_idx ON ONLY public.ast_usage USING btree (organization_id, at)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'ast_usage'"),
        );
    }

    /**
     * The AI suggestions kept for their requester (Version20261118100000): the app writes one once and then only reads
     * it, support reads under the tenant policy, and its only index starts with the organization.
     */
    #[Test]
    public function theSuggestionTableGrantsTheAppOnlyReadAndAddAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'ast_suggestion'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.ast_suggestion')"));
        self::assertSame(
            [true, true],
            $connection->fetchNumeric("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.ast_suggestion')"),
        );
        self::assertSame(
            ['CREATE UNIQUE INDEX ast_suggestion_pkey ON public.ast_suggestion USING btree (organization_id, id)'],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'ast_suggestion'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'ast_suggestion_model_check',
                'ast_suggestion_pkey',
                'ast_suggestion_prompt_version_check',
                'ast_suggestion_purpose_check',
                'ast_suggestion_result_check',
            ],
            $connection->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = 'public.ast_suggestion'::regclass AND contype IN ('c', 'p', 'u', 'f')"),
        );
    }

    /**
     * The stored answers of idempotent /api/v1 requests (Version20261119200000): the app reads, reserves and answers
     * them and never removes one (the purge comes with the retention job); support gets nothing, as an answer holds
     * presigned upload URLs (Version20261119210000); every index starts with the organization.
     */
    #[Test]
    public function theIdempotencyKeyTableGrantsOnlyTheAppReadAddAndChange(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE'],
            $this->grantsToOthersThanTheOwner($connection, 'api_idempotency_key'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.api_idempotency_key')"));
        self::assertSame(
            [true, true],
            $connection->fetchNumeric("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.api_idempotency_key')"),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX api_idempotency_key_pkey ON public.api_idempotency_key USING btree (organization_id, token_id, key)',
                'CREATE INDEX api_idempotency_key_created_at_idx ON public.api_idempotency_key USING btree (organization_id, created_at)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'api_idempotency_key'"),
        );
        self::assertEqualsCanonicalizing(
            ['api_idempotency_key_answer_check', 'api_idempotency_key_key_check', 'api_idempotency_key_pkey', 'api_idempotency_key_request_hash_check', 'api_idempotency_key_response_check', 'api_idempotency_key_status_check'],
            $connection->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = 'public.api_idempotency_key'::regclass AND contype IN ('c', 'p', 'u', 'f')"),
        );
    }

    /**
     * A stored answer is the response word for word, presigned put_url of POST /api/v1/uploads included, so support
     * tooling must not read it even under the tenant policy. The report drafts beside it stay readable, so the refusal
     * is the table's, not the connection's.
     */
    #[Test]
    public function theReadonlyRoleIsRefusedTheIdempotencyKeyTableButReadsTheReportDrafts(): void
    {
        $readonly = self::getContainer()->get('doctrine.dbal.readonly_connection');
        self::assertInstanceOf(Connection::class, $readonly);
        self::assertSame('pinround_readonly', $readonly->fetchOne('SELECT current_user'));

        self::assertSame(0, $readonly->fetchOne('SELECT count(*) FROM rvw_report_draft'));
        try {
            $readonly->fetchOne('SELECT count(*) FROM api_idempotency_key');
            self::fail('pinround_readonly could read api_idempotency_key.');
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
        }
    }

    /**
     * The report drafts (Version20261119190000): the app reads, adds, changes and removes them (Send and "Delete draft"
     * remove one), support reads under the tenant policy, and every index starts with the organization.
     */
    #[Test]
    public function theReportDraftTableGrantsTheAppItsFourRightsAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'rvw_report_draft'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.rvw_report_draft')"));
        self::assertSame(
            [true, true],
            $connection->fetchNumeric("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.rvw_report_draft')"),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX rvw_report_draft_pkey ON public.rvw_report_draft USING btree (organization_id, id)',
                'CREATE INDEX rvw_report_draft_owner_id_updated_at_idx ON public.rvw_report_draft USING btree (organization_id, owner_id, updated_at DESC)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'rvw_report_draft'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'rvw_report_draft_detail_check',
                'rvw_report_draft_pkey',
                'rvw_report_draft_project_key_check',
                'rvw_report_draft_screenshots_check',
                'rvw_report_draft_title_check',
                'rvw_report_draft_updated_at_check',
                'rvw_report_draft_version_check',
            ],
            $connection->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = 'public.rvw_report_draft'::regclass AND contype IN ('c', 'p', 'u', 'f')"),
        );
    }

    /**
     * The notebooks (Version20261120100000): the app reads, adds, changes and removes them ("Delete notebook" removes
     * one), support reads under the tenant policy, and every index starts with the organization.
     */
    #[Test]
    public function theNotebookTableGrantsTheAppItsFourRightsAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app DELETE', 'pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'rvw_notebook'),
        );
        self::assertSame('pinround_migrator', $connection->fetchOne("SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass('public.rvw_notebook')"));
        self::assertSame(
            [true, true],
            $connection->fetchNumeric("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.rvw_notebook')"),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX rvw_notebook_pkey ON public.rvw_notebook USING btree (organization_id, id)',
                'CREATE INDEX rvw_notebook_owner_id_last_used_at_idx ON public.rvw_notebook USING btree (organization_id, owner_id, last_used_at DESC, id DESC)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'rvw_notebook'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'rvw_notebook_last_used_at_check',
                'rvw_notebook_pkey',
                'rvw_notebook_scenarios_check',
                'rvw_notebook_screenshots_check',
                'rvw_notebook_title_check',
                'rvw_notebook_updated_at_check',
                'rvw_notebook_version_check',
            ],
            $connection->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = 'public.rvw_notebook'::regclass AND contype IN ('c', 'p', 'u', 'f')"),
        );
    }

    /**
     * The laptop AI prompt packages (Version20261118120000): the app reads, adds and changes them (a package takes its
     * one result by UPDATE) and never removes one; support reads under the tenant policy; resolve_laptop_package reads
     * only the three columns it matches on or returns. Every index starts with the organization but the id key: the
     * resolver looks a package up by id before its organization is known, and the primary key, led by
     * organization_id, cannot serve that.
     */
    #[Test]
    public function theLocalPackageTableGrantsTheAppNoDeleteAndTheResolverOnlyTheIdsItReads(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'ast_local_package'),
        );
        self::assertSame(
            ['pinround_migrator', true, true],
            $connection->fetchNumeric("SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.ast_local_package')"),
        );
        self::assertEqualsCanonicalizing(
            ['pinround_resolver SELECT id', 'pinround_resolver SELECT organization_id', 'pinround_resolver SELECT user_id'],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.ast_local_package'::regclass",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['tenant_isolation {public} ALL', 'resolver_read {pinround_resolver} SELECT'],
            $connection->fetchFirstColumn(
                "SELECT policyname || ' ' || roles::text || ' ' || cmd FROM pg_policies WHERE schemaname = 'public' AND tablename = 'ast_local_package'",
            ),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX ast_local_package_pkey ON public.ast_local_package USING btree (organization_id, id)',
                'CREATE INDEX ast_local_package_user_id_expires_at_idx ON public.ast_local_package USING btree (organization_id, user_id, expires_at)',
                'CREATE UNIQUE INDEX ast_local_package_id_key ON public.ast_local_package USING btree (id)',
                'CREATE INDEX ast_local_package_ticket_id_used_at_idx ON public.ast_local_package USING btree (organization_id, ticket_id, used_at DESC) WHERE ((used_at IS NOT NULL) AND (made_main_at IS NULL))',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'ast_local_package'"),
        );
        self::assertEqualsCanonicalizing(
            [
                "ast_local_package_purpose_check CHECK ((purpose = ANY (ARRAY['summary'::text, 'intake'::text])))",
                'ast_local_package_prompt_version_check CHECK (((char_length(prompt_version) >= 1) AND (char_length(prompt_version) <= 200)))',
                'ast_local_package_expires_at_check CHECK ((expires_at > created_at))',
                'ast_local_package_result_text_check CHECK (((char_length(result_text) >= 1) AND (char_length(result_text) <= 10000)))',
                "ast_local_package_model_check CHECK ((model ~ '^[a-z0-9:._-]{1,64}$'::text))",
                'ast_local_package_result_check CHECK ((((used_at IS NULL) = (result_text IS NULL)) AND ((used_at IS NULL) = (model IS NULL))))',
                'ast_local_package_result_label_check CHECK ((((used_at IS NULL) = (result_label IS NULL)) AND ((char_length(result_label) >= 1) AND (char_length(result_label) <= 400))))',
                'ast_local_package_made_main_at_check CHECK (((made_main_at IS NULL) OR (used_at IS NOT NULL)))',
                'ast_local_package_pkey PRIMARY KEY (organization_id, id)',
            ],
            $connection->fetchFirstColumn(
                "SELECT conname || ' ' || pg_get_constraintdef(oid) FROM pg_constraint
                 WHERE conrelid = 'public.ast_local_package'::regclass AND contype IN ('c', 'p', 'u', 'f')",
            ),
        );
    }

    /**
     * resolve_laptop_package runs as its owner pinround_resolver, which roles.sql gives no login, with a fixed
     * search_path; besides that owner only the app may call it, and it returns a package's organization and user.
     */
    #[Test]
    public function theLaptopPackageResolverReturnsOnlyTheOrganizationAndUserOfOnePackage(): void
    {
        $connection = $this->connection();

        self::assertSame(
            [
                'owner' => 'pinround_resolver',
                'is_security_definer' => true,
                'config' => '{"search_path=pg_catalog, public, pg_temp"}',
                'arguments' => 'p_package_id uuid',
                'result' => 'TABLE(organization_id uuid, user_id uuid)',
                'body' => 'SELECT p.organization_id, p.user_id FROM public.ast_local_package p WHERE p.id = p_package_id',
            ],
            $connection->fetchAssociative(
                "SELECT p.proowner::regrole::text AS owner, p.prosecdef AS is_security_definer, p.proconfig::text AS config,
                        pg_get_function_arguments(p.oid) AS arguments, pg_get_function_result(p.oid) AS result, btrim(p.prosrc) AS body
                 FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_laptop_package'",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['pinround_app', 'pinround_resolver'],
            $connection->fetchFirstColumn(
                "SELECT CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE acl.grantee::regrole::text END
                 FROM pg_proc p, aclexplode(p.proacl) acl
                 WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_laptop_package' AND acl.privilege_type = 'EXECUTE'",
            ),
        );
    }

    /**
     * idn_api_token (Version20261119100000, Version20261119150000, Version20261119160000), a tenant table: the app reads
     * and adds tokens and changes only the revocation, the last use and the expiry warning, never deletes; support
     * reads under the tenant policy; resolve_api_token and organizations_with_expiring_api_tokens read only the ids,
     * the hash, the three dates they check and the warning, never the name, prefix or scopes. Every index starts with the organization but the
     * token_hash key, which the resolver looks up before the organization is known.
     */
    #[Test]
    public function theApiTokenTableLetsTheAppChangeOnlyRevocationLastUseAndExpiryWarning(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT', 'pinround_readonly SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'idn_api_token'),
        );
        self::assertSame(
            ['pinround_migrator', true, true],
            $connection->fetchNumeric("SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.idn_api_token')"),
        );
        self::assertEqualsCanonicalizing(
            [
                'pinround_app UPDATE last_used_at', 'pinround_app UPDATE revoked_at', 'pinround_app UPDATE expiry_warned_at',
                'pinround_resolver SELECT id', 'pinround_resolver SELECT organization_id', 'pinround_resolver SELECT user_id',
                'pinround_resolver SELECT token_hash', 'pinround_resolver SELECT expires_at', 'pinround_resolver SELECT revoked_at',
                'pinround_resolver SELECT expiry_warned_at', 'pinround_resolver SELECT created_at',
            ],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.idn_api_token'::regclass",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['tenant_isolation {public} ALL', 'resolver_read {pinround_resolver} SELECT'],
            $connection->fetchFirstColumn(
                "SELECT policyname || ' ' || roles::text || ' ' || cmd FROM pg_policies WHERE schemaname = 'public' AND tablename = 'idn_api_token'",
            ),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX idn_api_token_pkey ON public.idn_api_token USING btree (organization_id, id)',
                'CREATE INDEX idn_api_token_user_id_created_at_idx ON public.idn_api_token USING btree (organization_id, user_id, created_at)',
                'CREATE UNIQUE INDEX idn_api_token_token_hash_key ON public.idn_api_token USING btree (token_hash)',
                'CREATE INDEX idn_api_token_expires_at_id_live_idx ON public.idn_api_token USING btree (organization_id, expires_at, id) WHERE (revoked_at IS NULL)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_api_token'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'idn_api_token_name_check CHECK (((char_length(name) >= 1) AND (char_length(name) <= 100)))',
                'idn_api_token_token_hash_check CHECK ((octet_length(token_hash) = 32))',
                "idn_api_token_prefix_check CHECK ((prefix ~ '^pr_[A-Za-z0-9_-]{8}$'::text))",
                "idn_api_token_scopes_check CHECK (((cardinality(scopes) >= 1) AND (scopes <@ ARRAY['read'::text, 'rounds:write'::text, 'comments:write'::text, 'tickets:move'::text, 'reports:write'::text, 'notebooks:write'::text])))",
                "idn_api_token_expires_at_check CHECK (((expires_at > created_at) AND (expires_at <= (created_at + '8760:00:00'::interval))))",
                'idn_api_token_pkey PRIMARY KEY (organization_id, id)',
            ],
            $connection->fetchFirstColumn(
                "SELECT conname || ' ' || pg_get_constraintdef(oid) FROM pg_constraint
                 WHERE conrelid = 'public.idn_api_token'::regclass AND contype IN ('c', 'p', 'u', 'f')",
            ),
        );
    }

    /**
     * resolve_api_token runs as its owner pinround_resolver with a fixed search_path; besides that owner only the app
     * may call it, and it returns the ids of a token that is neither revoked nor expired and whose person is still a
     * member of its organization (Version20261121193200).
     */
    #[Test]
    public function theApiTokenResolverReturnsOnlyTheIdsOfALiveToken(): void
    {
        $connection = $this->connection();

        self::assertSame(
            [
                'owner' => 'pinround_resolver',
                'is_security_definer' => true,
                'config' => '{"search_path=pg_catalog, public, pg_temp"}',
                'arguments' => 'p_token_hash bytea',
                'result' => 'TABLE(token_id uuid, organization_id uuid, user_id uuid)',
                'body' => 'SELECT t.id, t.organization_id, t.user_id FROM public.idn_api_token t'
                    .' WHERE t.token_hash = p_token_hash AND t.revoked_at IS NULL AND t.expires_at > now()'
                    .' AND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = t.organization_id AND m.user_id = t.user_id)',
            ],
            $connection->fetchAssociative(
                "SELECT p.proowner::regrole::text AS owner, p.prosecdef AS is_security_definer, p.proconfig::text AS config,
                        pg_get_function_arguments(p.oid) AS arguments, pg_get_function_result(p.oid) AS result, btrim(p.prosrc) AS body
                 FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_api_token'",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['pinround_app', 'pinround_resolver'],
            $connection->fetchFirstColumn(
                "SELECT CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE acl.grantee::regrole::text END
                 FROM pg_proc p, aclexplode(p.proacl) acl
                 WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_api_token' AND acl.privilege_type = 'EXECUTE'",
            ),
        );
    }

    /**
     * idn_oauth_client (Version20261121200000), a global table like idn_user: the app only reads and adds clients, as
     * nothing changes or removes one; support gets nothing, as no tenant policy would limit it, and no column grant
     * opens a column the table grant does not.
     */
    #[Test]
    public function theOAuthClientTableLetsTheAppOnlyReadAndAddAndSupportNothing(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'idn_oauth_client'),
        );
        self::assertSame(
            ['pinround_migrator', false, false],
            $connection->fetchNumeric("SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.idn_oauth_client')"),
        );
        self::assertSame(
            [],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.idn_oauth_client'::regclass",
            ),
        );
        self::assertSame(
            [],
            $connection->fetchFirstColumn("SELECT policyname FROM pg_policies WHERE schemaname = 'public' AND tablename = 'idn_oauth_client'"),
        );
        self::assertSame(
            ['CREATE UNIQUE INDEX idn_oauth_client_pkey ON public.idn_oauth_client USING btree (client_id)'],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_oauth_client'"),
        );
    }

    /**
     * idn_oauth_authorization_code (Version20261121220000), a tenant table: the app reads and adds codes and changes
     * only used_at, never deletes; support gets nothing; resolve_oauth_authorization_code reads only the ids, the hash
     * and the two dates it checks, never the client, redirect URI, challenge or scopes. Every index starts with the
     * organization but the code_hash key, which the resolver looks up before the organization is known.
     */
    #[Test]
    public function theOAuthAuthorizationCodeTableLetsTheAppChangeOnlyTheUseAndSupportNothing(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'idn_oauth_authorization_code'),
        );
        self::assertSame(
            ['pinround_migrator', true, true],
            $connection->fetchNumeric("SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.idn_oauth_authorization_code')"),
        );
        self::assertEqualsCanonicalizing(
            [
                'pinround_app UPDATE used_at',
                'pinround_resolver SELECT organization_id', 'pinround_resolver SELECT user_id', 'pinround_resolver SELECT code_hash',
                'pinround_resolver SELECT expires_at', 'pinround_resolver SELECT used_at',
            ],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.idn_oauth_authorization_code'::regclass",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['tenant_isolation {public} ALL', 'resolver_read {pinround_resolver} SELECT'],
            $connection->fetchFirstColumn(
                "SELECT policyname || ' ' || roles::text || ' ' || cmd FROM pg_policies WHERE schemaname = 'public' AND tablename = 'idn_oauth_authorization_code'",
            ),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX idn_oauth_authorization_code_pkey ON public.idn_oauth_authorization_code USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX idn_oauth_authorization_code_code_hash_key ON public.idn_oauth_authorization_code USING btree (code_hash)',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_oauth_authorization_code'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'idn_oauth_authorization_code_code_hash_check CHECK ((octet_length(code_hash) = 32))',
                "idn_oauth_authorization_code_client_id_check CHECK ((client_id ~ '^[A-Za-z0-9_-]{32}$'::text))",
                'idn_oauth_authorization_code_redirect_uri_check CHECK (((octet_length(redirect_uri) >= 1) AND (octet_length(redirect_uri) <= 512)))',
                "idn_oauth_authorization_code_code_challenge_check CHECK ((code_challenge ~ '^[A-Za-z0-9._~-]{43,128}$'::text))",
                "idn_oauth_authorization_code_scopes_check CHECK (((cardinality(scopes) >= 1) AND (scopes <@ ARRAY['read'::text, 'rounds:write'::text, 'comments:write'::text, 'tickets:move'::text, 'reports:write'::text, 'notebooks:write'::text])))",
                "idn_oauth_authorization_code_expires_at_check CHECK (((expires_at > created_at) AND (expires_at <= (created_at + '00:01:00'::interval))))",
                'idn_oauth_authorization_code_pkey PRIMARY KEY (organization_id, id)',
            ],
            $connection->fetchFirstColumn(
                "SELECT conname || ' ' || pg_get_constraintdef(oid) FROM pg_constraint
                 WHERE conrelid = 'public.idn_oauth_authorization_code'::regclass AND contype IN ('c', 'p', 'u', 'f')",
            ),
        );
    }

    /**
     * resolve_oauth_authorization_code runs as its owner pinround_resolver with a fixed search_path; besides that owner
     * only the app may call it, and it returns the organization of a code that is used, or unused and not expired, and
     * whose person is still a member of that organization (Version20261122000000).
     */
    #[Test]
    public function theOAuthAuthorizationCodeResolverReturnsOnlyTheOrganizationOfAUsedOrLiveCode(): void
    {
        $connection = $this->connection();

        self::assertSame(
            [
                'owner' => 'pinround_resolver',
                'is_security_definer' => true,
                'config' => '{"search_path=pg_catalog, public, pg_temp"}',
                'arguments' => 'p_code_hash bytea',
                'result' => 'TABLE(organization_id uuid)',
                'body' => 'SELECT c.organization_id FROM public.idn_oauth_authorization_code c'
                    ."\n".'WHERE c.code_hash = p_code_hash AND (c.used_at IS NOT NULL OR c.expires_at > now())'
                    ."\n".'AND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = c.organization_id AND m.user_id = c.user_id)',
            ],
            $connection->fetchAssociative(
                "SELECT p.proowner::regrole::text AS owner, p.prosecdef AS is_security_definer, p.proconfig::text AS config,
                        pg_get_function_arguments(p.oid) AS arguments, pg_get_function_result(p.oid) AS result, btrim(p.prosrc, E' \\n') AS body
                 FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_oauth_authorization_code'",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['pinround_app', 'pinround_resolver'],
            $connection->fetchFirstColumn(
                "SELECT CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE acl.grantee::regrole::text END
                 FROM pg_proc p, aclexplode(p.proacl) acl
                 WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_oauth_authorization_code' AND acl.privilege_type = 'EXECUTE'",
            ),
        );
    }

    /**
     * idn_oauth_refresh_token (Version20261121230000, Version20261122020000), a tenant table: the app reads and adds
     * tokens and changes only used_at and revoked_at, never deletes; support gets nothing; resolve_oauth_refresh_token
     * reads only the ids, the hash, the expiry and the revocation, never the client, family, scopes or use. Every index
     * starts with the organization but the token_hash key, which the resolver looks up before the organization is known.
     */
    #[Test]
    public function theOAuthRefreshTokenTableLetsTheAppChangeOnlyTheUseAndRevocationAndSupportNothing(): void
    {
        $connection = $this->connection();

        self::assertEqualsCanonicalizing(
            ['pinround_app INSERT', 'pinround_app SELECT'],
            $this->grantsToOthersThanTheOwner($connection, 'idn_oauth_refresh_token'),
        );
        self::assertSame(
            ['pinround_migrator', true, true],
            $connection->fetchNumeric("SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass('public.idn_oauth_refresh_token')"),
        );
        self::assertEqualsCanonicalizing(
            [
                'pinround_app UPDATE used_at', 'pinround_app UPDATE revoked_at',
                'pinround_resolver SELECT organization_id', 'pinround_resolver SELECT user_id', 'pinround_resolver SELECT token_hash',
                'pinround_resolver SELECT expires_at', 'pinround_resolver SELECT revoked_at',
            ],
            $connection->fetchFirstColumn(
                "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type || ' ' || a.attname
                 FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = 'public.idn_oauth_refresh_token'::regclass",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['tenant_isolation {public} ALL', 'resolver_read {pinround_resolver} SELECT'],
            $connection->fetchFirstColumn(
                "SELECT policyname || ' ' || roles::text || ' ' || cmd FROM pg_policies WHERE schemaname = 'public' AND tablename = 'idn_oauth_refresh_token'",
            ),
        );
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX idn_oauth_refresh_token_pkey ON public.idn_oauth_refresh_token USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX idn_oauth_refresh_token_token_hash_key ON public.idn_oauth_refresh_token USING btree (token_hash)',
                'CREATE INDEX idn_oauth_refresh_token_family_id_idx ON public.idn_oauth_refresh_token USING btree (organization_id, family_id)',
                'CREATE INDEX idn_oauth_refresh_token_access_token_id_idx ON public.idn_oauth_refresh_token USING btree (organization_id, access_token_id)',
                'CREATE INDEX idn_oauth_refresh_token_user_id_family_created_at_live_idx ON public.idn_oauth_refresh_token USING btree (organization_id, user_id, family_created_at DESC, family_id DESC) WHERE ((revoked_at IS NULL) AND (used_at IS NULL))',
                'CREATE INDEX idn_oauth_refresh_token_family_created_at_live_idx ON public.idn_oauth_refresh_token USING btree (organization_id, family_created_at DESC, family_id DESC) WHERE ((revoked_at IS NULL) AND (used_at IS NULL))',
            ],
            $connection->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'idn_oauth_refresh_token'"),
        );
        self::assertEqualsCanonicalizing(
            [
                'idn_oauth_refresh_token_token_hash_check CHECK ((octet_length(token_hash) = 32))',
                "idn_oauth_refresh_token_client_id_check CHECK ((client_id ~ '^[A-Za-z0-9_-]{32}$'::text))",
                "idn_oauth_refresh_token_scopes_check CHECK (((cardinality(scopes) >= 1) AND (scopes <@ ARRAY['read'::text, 'rounds:write'::text, 'comments:write'::text, 'tickets:move'::text, 'reports:write'::text, 'notebooks:write'::text])))",
                "idn_oauth_refresh_token_expires_at_check CHECK (((expires_at > created_at) AND (expires_at <= (created_at + '30 days'::interval))))",
                'idn_oauth_refresh_token_family_created_at_check CHECK ((family_created_at <= created_at))',
                'idn_oauth_refresh_token_pkey PRIMARY KEY (organization_id, id)',
            ],
            $connection->fetchFirstColumn(
                "SELECT conname || ' ' || pg_get_constraintdef(oid) FROM pg_constraint
                 WHERE conrelid = 'public.idn_oauth_refresh_token'::regclass AND contype IN ('c', 'p', 'u', 'f')",
            ),
        );
    }

    /**
     * resolve_oauth_refresh_token runs as its owner pinround_resolver with a fixed search_path; besides that owner only
     * the app may call it, and it returns the organization of a token that is neither revoked nor expired and whose
     * person is still a member of that organization. A used token is found, so its reuse can revoke its family.
     */
    #[Test]
    public function theOAuthRefreshTokenResolverReturnsOnlyTheOrganizationOfAnUnrevokedLiveToken(): void
    {
        $connection = $this->connection();

        self::assertSame(
            [
                'owner' => 'pinround_resolver',
                'is_security_definer' => true,
                'config' => '{"search_path=pg_catalog, public, pg_temp"}',
                'arguments' => 'p_token_hash bytea',
                'result' => 'TABLE(organization_id uuid)',
                'body' => 'SELECT t.organization_id FROM public.idn_oauth_refresh_token t'
                    ."\n".'WHERE t.token_hash = p_token_hash AND t.revoked_at IS NULL AND t.expires_at > now()'
                    ."\n".'AND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = t.organization_id AND m.user_id = t.user_id)',
            ],
            $connection->fetchAssociative(
                "SELECT p.proowner::regrole::text AS owner, p.prosecdef AS is_security_definer, p.proconfig::text AS config,
                        pg_get_function_arguments(p.oid) AS arguments, pg_get_function_result(p.oid) AS result, btrim(p.prosrc, E' \\n') AS body
                 FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_oauth_refresh_token'",
            ),
        );
        self::assertEqualsCanonicalizing(
            ['pinround_app', 'pinround_resolver'],
            $connection->fetchFirstColumn(
                "SELECT CASE acl.grantee WHEN 0 THEN 'PUBLIC' ELSE acl.grantee::regrole::text END
                 FROM pg_proc p, aclexplode(p.proacl) acl
                 WHERE p.pronamespace = 'public'::regnamespace AND p.proname = 'resolve_oauth_refresh_token' AND acl.privilege_type = 'EXECUTE'",
            ),
        );
    }

    /**
     * The upload slot and picture tables (Version20261115120000): the app reads, adds and changes them and removes
     * neither, as a used slot and a rejected picture keep their rows; support reads under the tenant policy.
     */
    #[Test]
    public function theMediaTablesGrantTheAppNoDeleteAndSupportOnlyRead(): void
    {
        $connection = $this->connection();

        foreach (['med_upload_intent', 'med_object'] as $table) {
            self::assertEqualsCanonicalizing(
                ['pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE', 'pinround_readonly SELECT'],
                $this->grantsToOthersThanTheOwner($connection, $table),
                $table,
            );
            self::assertSame(
                ['pinround_migrator', true, true],
                $connection->fetchNumeric('SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]),
                $table,
            );
            self::assertSame([], $connection->fetchFirstColumn(
                'SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = to_regclass(?)',
                ['public.'.$table],
            ), $table);
        }
    }

    /**
     * The round, picture, pin, check item, area and general note tables (Version20261115180000, Version20261116100000,
     * Version20261116120000, Version20261116130000, Version20261122130000): the app reads, adds and changes them, removes
     * the pictures and pins a draft drops and never a round, a check item or an area, which goes only with its round or
     * picture; a general note it only reads and adds, as nobody changes one and it goes only with its round; support
     * reads under the tenant policy. rvw_screenshot_verdict is gone (Version20261122130000).
     */
    #[Test]
    public function theRoundTablesGrantTheAppDeleteOnlyOnScreenshotsAndPinsAndSupportOnlyRead(): void
    {
        $connection = $this->connection();
        $readAddChange = ['pinround_app SELECT', 'pinround_app INSERT', 'pinround_app UPDATE', 'pinround_readonly SELECT'];

        foreach (['rvw_round' => $readAddChange, 'rvw_screenshot' => [...$readAddChange, 'pinround_app DELETE'], 'rvw_pin' => [...$readAddChange, 'pinround_app DELETE'], 'rvw_check_item' => $readAddChange, 'rvw_area' => $readAddChange, 'rvw_round_note' => ['pinround_app SELECT', 'pinround_app INSERT', 'pinround_readonly SELECT']] as $table => $grants) {
            self::assertEqualsCanonicalizing(
                $grants,
                $this->grantsToOthersThanTheOwner($connection, $table),
                $table,
            );
            self::assertSame(
                ['pinround_migrator', true, true],
                $connection->fetchNumeric('SELECT relowner::regrole::text, relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]),
                $table,
            );
            self::assertSame([], $connection->fetchFirstColumn(
                'SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = to_regclass(?)',
                ['public.'.$table],
            ), $table);
        }
        self::assertNull($connection->fetchOne('SELECT to_regclass(?)', ['public.rvw_screenshot_verdict']));
    }

    /**
     * Every index of the media tables starts with organization_id: expired slots are found by time, a picture already
     * uploaded by its hash, and the pictures of one status by age; the storage quota and a person's open slots are
     * read from the index alone.
     */
    #[Test]
    public function theMediaIndexesAllStartWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX med_upload_intent_pkey ON public.med_upload_intent USING btree (organization_id, id)',
                'CREATE INDEX med_upload_intent_expires_at_idx ON public.med_upload_intent USING btree (organization_id, expires_at)',
                'CREATE UNIQUE INDEX med_object_pkey ON public.med_object USING btree (organization_id, id)',
                'CREATE INDEX med_object_sha256_idx ON public.med_object USING btree (organization_id, sha256)',
                'CREATE INDEX med_object_status_created_at_idx ON public.med_object USING btree (organization_id, status, created_at)',
                'CREATE INDEX med_object_status_bytes_idx ON public.med_object USING btree (organization_id, status, bytes)',
                'CREATE INDEX med_upload_intent_open_idx ON public.med_upload_intent USING btree (organization_id, expires_at, user_id, max_bytes) WHERE (used_at IS NULL)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ('med_upload_intent', 'med_object')"),
        );
    }

    /**
     * Every index of an org_ table starts with organization_id but the documented exceptions: the global slug key, the
     * user_id index of resolve_user_orgs and the token_hash key of resolve_invitation, which run before the tenant is
     * known.
     */
    #[Test]
    public function onlyTheDocumentedOrganizationIndexesDoNotStartWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(self::DOCUMENTED_ORGANIZATION_INDEXES_NOT_LED_BY_THE_ORGANIZATION, self::organizationIndexesNotLedByTheOrganization($this->connection()));
    }

    /**
     * An index whose first key is an expression has no column there (indkey[0] = 0), so an inner join would drop it;
     * one on an expression of organization_id does not start with the column either.
     */
    #[Test]
    public function anOrganizationIndexLedByAnExpressionIsReported(): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $migrator->beginTransaction();
        try {
            $migrator->executeStatement('CREATE INDEX org_membership_expression_probe_idx ON org_membership ((organization_id::text), user_id)');

            self::assertEqualsCanonicalizing(
                [
                    ...self::DOCUMENTED_ORGANIZATION_INDEXES_NOT_LED_BY_THE_ORGANIZATION,
                    'CREATE INDEX org_membership_expression_probe_idx ON public.org_membership USING btree (((organization_id)::text), user_id)',
                ],
                self::organizationIndexesNotLedByTheOrganization($migrator),
            );
        } finally {
            $migrator->rollBack();
        }
    }

    /** The tables no entity maps; the diff must neither drop them nor hide a table that is mapped. */
    #[Test]
    public function theSchemaFilterHidesOnlyTheTablesNoEntityMapsOnBothConnections(): void
    {
        $hidden = ['processed_message', 'idn_login_attempt', 'web_home_focus', 'aud_event', 'aud_event_202611', 'aud_event_209912', 'public.aud_event', 'public.processed_message', 'public.idn_login_attempt', 'public.web_home_focus', 'wf_rule_set', 'public.wf_rule_set', 'ins_ticket_turn', 'public.ins_ticket_turn', 'rvw_report_draft', 'public.rvw_report_draft', 'rvw_notebook', 'public.rvw_notebook', 'api_idempotency_key', 'public.api_idempotency_key', 'ins_project_counts', 'public.ins_project_counts', 'ins_project_count_ticket', 'tkt_summary', 'public.tkt_summary', 'tkt_ticket_event', 'public.tkt_ticket_event', 'tkt_ticket_event_202611', 'tkt_ticket_event_209912', 'ntf_notification', 'public.ntf_notification', 'ntf_notification_202611', 'ntf_notification_209912', 'ntf_mute', 'public.ntf_mute', 'ntf_delivery', 'public.ntf_delivery', 'rvw_round', 'public.rvw_round', 'rvw_screenshot', 'public.rvw_screenshot', 'rvw_pin', 'public.rvw_pin', 'rvw_area', 'public.rvw_area', 'rvw_check_item', 'public.rvw_check_item', 'rvw_round_note', 'public.rvw_round_note', 'ast_usage', 'public.ast_usage', 'ast_usage_202611', 'ast_usage_209912', 'ast_suggestion', 'public.ast_suggestion', 'ast_local_package', 'public.ast_local_package'];
        $kept = ['messenger_outbox', 'messenger_failed', 'aud_export', 'aud_event_note', 'aud_event_2026', 'aud_events', 'processed_message_v2', 'tkt_ticket_events', 'tkt_ticket_event_2026', 'tkt_ticket_event_note', 'zz_tenant_probe', 'idn_user', 'idn_credential', 'idn_login_attempts', 'idn_password_reset', 'idn_api_token', 'idn_oauth_authorization_code', 'idn_oauth_refresh_token', 'web_home_focuses', 'web_home', 'wf_step', 'wf_rule_sets', 'ins_ticket_turns', 'ins_ticket', 'ins_project_count', 'ins_project_count_tickets', 'tkt_ticket', 'tkt_summaries', 'tkt_project', 'cnv_message', 'cnv_reply', 'ntf_ping', 'ntf_notifications', 'ntf_notification_2026', 'ntf_notification_note', 'ntf_mutes', 'ntf_delivery_log', 'med_upload_intent', 'med_object', 'rvw_rounds', 'rvw_round_notes', 'rvw_round_note_v2', 'rvw_screenshots', 'rvw_pins', 'rvw_areas', 'rvw_area_note', 'rvw_check_items', 'rvw_check_item_note', 'rvw_report_drafts', 'api_idempotency_keys', 'rvw_report_draft_note', 'rvw_notebooks', 'rvw_notebook_note', 'ast_usages', 'ast_usage_2026', 'ast_usage_note', 'ast_explanation', 'ast_suggestions', 'ast_suggestion_note', 'ast_suggestion_202611', 'ast_local_packages', 'ast_local_package_note', 'ast_local_package_202611', 'ast_local', 'public.ast_local_packages'];

        foreach (['doctrine.dbal.default_connection', 'doctrine.dbal.migrator_connection'] as $connectionId) {
            $connection = self::getContainer()->get($connectionId);
            self::assertInstanceOf(Connection::class, $connection);
            $isKept = $connection->getConfiguration()->getSchemaAssetsFilter();

            self::assertSame([], array_values(array_filter($hidden, static fn (string $table): bool => (bool) $isKept($table))), $connectionId);
            self::assertSame($kept, array_values(array_filter($kept, static fn (string $table): bool => (bool) $isKept($table))), $connectionId);
        }
    }

    /**
     * What doctrine:migrations:diff runs (DiffGenerator: the filtered database against the filtered ORM schema, both
     * ways), so a new hand-written table is either mapped or added to the schema_filter. The test-only zz_ entities have
     * no migration and are left out, and so is the migrations' own table, which the bundle hides only while one of its
     * commands runs.
     */
    #[Test]
    public function doctrineMigrationsDiffFindsNoChanges(): void
    {
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $migratorConnection = $migrations->getConnection();
        $schemaManager = $migratorConnection->createSchemaManager();
        $isKept = $migratorConnection->getConfiguration()->getSchemaAssetsFilter();
        $metadataStorage = $migrations->getConfiguration()->getMetadataStorageConfiguration();
        self::assertInstanceOf(TableMetadataStorageConfiguration::class, $metadataStorage);
        $migrationsTable = $metadataStorage->getTableName();
        $withoutHiddenTables = static function (Schema $schema) use ($isKept, $migrationsTable): Schema {
            foreach ($schema->getTables() as $table) {
                $name = $table->getObjectName()->toString();
                if (!(bool) $isKept($name) || str_starts_with($name, 'zz_') || $migrationsTable === $name) {
                    $schema = $schema->edit()->dropTable($table->getObjectName())->create();
                }
            }

            return $schema;
        };
        $migratedSchema = $withoutHiddenTables($schemaManager->introspectSchema());
        $expectedSchema = $withoutHiddenTables($migrations->getSchemaProvider()->createSchema());
        $comparator = $schemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));
        $platform = $migratorConnection->getDatabasePlatform();

        self::assertSame([], $platform->getAlterSchemaSQL($comparator->compareSchemas($migratedSchema, $expectedSchema)));
        self::assertSame([], $platform->getAlterSchemaSQL($comparator->compareSchemas($expectedSchema, $migratedSchema)));
    }

    /** What doctrine:migrations:diff compares, limited to the Messenger tables: the migration built them as Messenger would. */
    #[Test]
    public function theMigratedMessengerTablesMatchTheSchemaMessengerExpects(): void
    {
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $migratorConnection = $migrations->getConnection();
        $schemaManager = $migratorConnection->createSchemaManager();
        $expectedSchema = $migrations->getSchemaProvider()->createSchema();
        $migratedSchema = $schemaManager->introspectSchema();
        $comparator = $schemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));

        foreach (self::MESSENGER_TABLES as $table) {
            self::assertTrue($expectedSchema->hasTable($table), $table.' is not in the schema Doctrine expects.');
            $tableDiff = $comparator->compareTables($migratedSchema->getTable($table), $expectedSchema->getTable($table));

            self::assertSame([], $migratorConnection->getDatabasePlatform()->getAlterTableSQL($tableDiff), $table);
        }
    }

    #[Test]
    public function theMigrationsRanAsTheMigrator(): void
    {
        // roles.sql re-owns tables but cannot re-own extensions, so only a migration run as the migrator makes it their owner.
        self::assertEqualsCanonicalizing(
            ['btree_gin', 'citext', 'pg_trgm', 'pgcrypto', 'unaccent'],
            $this->connection()->fetchFirstColumn("SELECT extname FROM pg_extension WHERE extowner = 'pinround_migrator'::regrole"),
            'A test database migrated before the roles existed keeps the old owner: drop pinround_test, then run make db-roles migrate-test.',
        );
    }

    #[Test]
    public function theMigrationsUseTheMigratorConnectionAndTheOrmSchema(): void
    {
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertSame('pinround_migrator', $migrations->getConnection()->fetchOne('SELECT current_user'));
        self::assertInstanceOf(OrmSchemaProvider::class, $migrations->getSchemaProvider());
    }

    /**
     * @return list<string> the definition of every index of an org_ table whose first key is not the organization_id
     *                      column, expressions included
     */
    private static function organizationIndexesNotLedByTheOrganization(Connection $connection): array
    {
        $definitions = $connection->fetchFirstColumn(
            "SELECT pg_get_indexdef(i.indexrelid)
             FROM pg_index i JOIN pg_class c ON c.oid = i.indrelid
             LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = i.indkey[0]
             WHERE c.relnamespace = 'public'::regnamespace AND c.relname LIKE 'org\\_%'
             AND a.attname IS DISTINCT FROM 'organization_id'",
        );

        return array_map(static function (mixed $definition): string {
            self::assertIsString($definition);

            return $definition;
        }, $definitions);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * @return list<mixed> "grantee PRIVILEGE" pairs on a table in public, the owner's own rights left out
     */
    private function grantsToOthersThanTheOwner(Connection $connection, string $table): array
    {
        return $connection->fetchFirstColumn(
            "SELECT acl.grantee::regrole::text || ' ' || acl.privilege_type
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE c.oid = to_regclass('public.' || quote_ident(?)) AND acl.grantee <> c.relowner",
            [$table],
        );
    }
}
