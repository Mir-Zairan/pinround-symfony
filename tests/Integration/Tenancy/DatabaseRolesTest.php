<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use Doctrine\DBAL\Connection;
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

        foreach ($expectedGrants as $table => $grants) {
            self::assertEqualsCanonicalizing($grants, $this->grantsToOthersThanTheOwner($connection, $table), $table);
            self::assertSame('pinround_migrator', $connection->fetchOne('SELECT relowner::regrole::text FROM pg_class WHERE oid = to_regclass(?)', ['public.'.$table]), $table);
            self::assertSame([], $connection->fetchFirstColumn(
                'SELECT a.attname FROM pg_attribute a, aclexplode(a.attacl) acl WHERE a.attrelid = to_regclass(?)',
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
     * Every index of the media tables starts with organization_id: expired slots are found by time, a picture already
     * uploaded by its hash, and the pictures of one status by age.
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
        $hidden = ['processed_message', 'idn_login_attempt', 'web_home_focus', 'aud_event', 'aud_event_202611', 'aud_event_209912', 'public.aud_event', 'public.processed_message', 'public.idn_login_attempt', 'public.web_home_focus', 'wf_rule_set', 'public.wf_rule_set', 'ins_ticket_turn', 'public.ins_ticket_turn', 'ins_project_counts', 'public.ins_project_counts', 'ins_project_count_ticket', 'tkt_summary', 'public.tkt_summary', 'tkt_ticket_event', 'public.tkt_ticket_event', 'tkt_ticket_event_202611', 'tkt_ticket_event_209912', 'ntf_notification', 'public.ntf_notification', 'ntf_notification_202611', 'ntf_notification_209912', 'ntf_mute', 'public.ntf_mute', 'ntf_delivery', 'public.ntf_delivery'];
        $kept = ['messenger_outbox', 'messenger_failed', 'aud_export', 'aud_event_note', 'aud_event_2026', 'aud_events', 'processed_message_v2', 'tkt_ticket_events', 'tkt_ticket_event_2026', 'tkt_ticket_event_note', 'zz_tenant_probe', 'idn_user', 'idn_credential', 'idn_login_attempts', 'idn_password_reset', 'web_home_focuses', 'web_home', 'wf_step', 'wf_rule_sets', 'ins_ticket_turns', 'ins_ticket', 'ins_project_count', 'ins_project_count_tickets', 'tkt_ticket', 'tkt_summaries', 'tkt_project', 'cnv_message', 'cnv_reply', 'ntf_ping', 'ntf_notifications', 'ntf_notification_2026', 'ntf_notification_note', 'ntf_mutes', 'ntf_delivery_log', 'med_upload_intent', 'med_object'];

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
