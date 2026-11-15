<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Infrastructure\Doctrine\Migration\ResolverFunctions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The tenancy rules of ADR-0005 read from the PostgreSQL catalog. Each check returns one line per
 * offending object, so an empty list means the rule holds. A tenant table is a table with an
 * `organization_id` column, plus every partition or inheritance child below it, in any schema but the
 * system ones. Objects an extension owns are checked too: the migrator owns the extensions, so it could
 * hide an object with ALTER EXTENSION ... ADD, and none of the installed extensions brings tables or
 * SECURITY DEFINER functions.
 */
final readonly class TenancyCatalog
{
    private const string REFERENCE_TABLE = 'zz_tenancy_catalog_reference';

    /** Every schema but PostgreSQL's own and the per-session temporary ones; any other the migrator could create. */
    private const string USER_SCHEMAS = "(SELECT n.oid FROM pg_namespace n
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
          AND n.nspname NOT LIKE 'pg\\_temp\\_%' AND n.nspname NOT LIKE 'pg\\_toast\\_temp\\_%')";

    /**
     * The columns pinround_resolver may SELECT whose type counts as text, see resolverTextColumnsWithout…(): on a table
     * with a resolver_read policy by any route (its own grant, PUBLIC, a table-wide grant, a role it belongs to), and on
     * any other table by a grant that names it.
     */
    private const string RESOLVER_TEXT_COLUMNS = "WITH resolver_text_column AS (
            SELECT a.attrelid, a.attnum, a.attname, a.atttypid, a.atttypmod
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_type t ON t.oid = a.atttypid
            JOIN pg_type v ON v.oid = CASE WHEN t.typcategory = 'A' THEN t.typelem ELSE t.oid END
            WHERE c.relnamespace IN ".self::USER_SCHEMAS." AND a.attnum > 0 AND NOT a.attisdropped
              AND (EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = a.attrelid AND p.polname = 'resolver_read')
                       AND has_column_privilege('pinround_resolver', a.attrelid, a.attnum, 'SELECT')
                   OR EXISTS (SELECT 1 FROM aclexplode(a.attacl) acl WHERE acl.grantee = 'pinround_resolver'::regrole AND acl.privilege_type = 'SELECT')
                   OR EXISTS (SELECT 1 FROM aclexplode(c.relacl) acl WHERE acl.grantee = 'pinround_resolver'::regrole AND acl.privilege_type = 'SELECT'))
              AND v.typcategory NOT IN ('B', 'N', 'D', 'T', 'E')
              AND COALESCE(NULLIF(v.typbasetype, 0), v.oid) NOT IN ('uuid'::regtype, 'bytea'::regtype)
        )";

    /**
     * The functions pinround_resolver owns, and those it may run in a user schema: a resolver function runs whatever it
     * calls with pinround_resolver's rights, so a function it may run reads as the resolver too.
     */
    private const string RESOLVER_FUNCTIONS = "WITH resolver_function AS MATERIALIZED (
            SELECT p.oid, p.proname, p.prokind, p.prolang, p.proargnames, p.proargmodes,
                   p.proowner = 'pinround_resolver'::regrole AS is_resolver_owned
            FROM pg_proc p
            WHERE p.proowner = 'pinround_resolver'::regrole
               OR p.pronamespace IN ".self::USER_SCHEMAS." AND has_function_privilege('pinround_resolver', p.oid, 'EXECUTE')
        )";

    /** How PostgreSQL prints `CHECK (column IN ('a', 'b'))` on a text column; %s is the column as quote_ident() gives it. */
    private const string CLOSED_SET_CHECK = "/^CHECK \\(\\(%s = ANY \\(ARRAY\\['[a-z0-9_]+'::text(?:, '[a-z0-9_]+'::text)*\\]\\)\\)\\)\\z/";

    /** The comparison by which a resolver function keeps to the transaction's tenant (data-model.md › Row-level security). */
    private const string TENANT_BINDING = "/\\bp_organization_id\\s*=\\s*NULLIF\\(\\s*current_setting\\('app\\.org_id',\\s*true\\),\\s*''\\s*\\)::uuid/";

    /** A `--` comment to the end of its line, or a block comment with the block comments nested in it, as PostgreSQL nests them. */
    private const string SQL_COMMENT = '~--[^\n]*|(/\*(?:(?!/\*|\*/)[\s\S]|(?1))*+\*/)~';

    private const string TENANT_TABLES = 'WITH RECURSIVE tenant_table AS (
            SELECT c.oid FROM pg_class c
            WHERE c.relnamespace IN '.self::USER_SCHEMAS."
              AND c.relkind IN ('r', 'p')
              AND EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = c.oid AND a.attname = 'organization_id' AND a.attnum > 0 AND NOT a.attisdropped
              )
            UNION
            SELECT i.inhrelid FROM pg_inherits i JOIN tenant_table t ON i.inhparent = t.oid
        )";

    /**
     * @param array<string, array<string, mixed>> $policiesTenantTableWrites policy name => shape, see read()
     */
    private function __construct(private Connection $connection, private array $policiesTenantTableWrites)
    {
    }

    /**
     * Learns the policies TenantTable::enableRls and ::allowResolverRead write, as PostgreSQL stores them,
     * from a table the migrator creates and rolls back; then reads the catalog through $connection.
     */
    public static function read(Connection $connection, Connection $migrator): self
    {
        $migrator->beginTransaction();
        try {
            $migrator->executeStatement(\sprintf('CREATE TABLE %s (id uuid, organization_id uuid NOT NULL)', self::REFERENCE_TABLE));
            foreach ([...TenantTable::enableRls(self::REFERENCE_TABLE), ...TenantTable::allowResolverRead(self::REFERENCE_TABLE, ['id', 'organization_id'])] as $sql) {
                $migrator->executeStatement($sql);
            }
            $policies = [];
            foreach (self::policiesOnTenantTables($migrator) as $policy) {
                if (self::REFERENCE_TABLE === $policy['table_name']) {
                    $policies[self::text($policy['name'])] = self::shapeOf($policy);
                }
            }
        } finally {
            $migrator->rollBack();
        }

        return new self($connection, $policies);
    }

    /**
     * @return list<string> "table: what is missing", for each tenant table without ENABLE and FORCE ROW LEVEL
     *                      SECURITY and the tenant_isolation policy exactly as TenantTable::enableRls writes it
     */
    public function tenantTablesWithoutIsolation(): array
    {
        $tenantIsolationTables = [];
        foreach (self::policiesOnTenantTables($this->connection) as $policy) {
            if ('tenant_isolation' === $policy['name'] && self::shapeOf($policy) === $this->policiesTenantTableWrites['tenant_isolation']) {
                $tenantIsolationTables[] = $policy['table_name'];
            }
        }

        $violations = [];
        foreach ($this->connection->fetchAllAssociative(
            self::TENANT_TABLES.' SELECT c.oid::regclass::text AS table_name, c.relrowsecurity, c.relforcerowsecurity
             FROM tenant_table t JOIN pg_class c ON c.oid = t.oid
             WHERE c.relkind IN (\'r\', \'p\')
             ORDER BY 1',
        ) as $table) {
            $missing = array_keys(array_filter([
                'ROW LEVEL SECURITY off' => true !== $table['relrowsecurity'],
                'FORCE ROW LEVEL SECURITY off' => true !== $table['relforcerowsecurity'],
                'no tenant_isolation policy as TenantTable::enableRls writes it' => !\in_array($table['table_name'], $tenantIsolationTables, true),
            ]));
            if ([] !== $missing) {
                $violations[] = self::text($table['table_name']).': '.implode(', ', $missing);
            }
        }

        return $violations;
    }

    /**
     * @return list<string> "table: policy", for each policy on a tenant table that is neither tenant_isolation nor
     *                      resolver_read exactly as TenantTable::allowResolverRead writes it (FOR SELECT TO pinround_resolver)
     */
    public function foreignPoliciesOnTenantTables(): array
    {
        $violations = [];
        foreach (self::policiesOnTenantTables($this->connection) as $policy) {
            if ('tenant_isolation' !== $policy['name'] && self::shapeOf($policy) !== $this->policiesTenantTableWrites['resolver_read']) {
                $violations[] = self::text($policy['table_name']).': '.self::text($policy['name']);
            }
        }

        return $violations;
    }

    /**
     * Superusers are left out: they bypass row-level security whatever rolbypassrls says, and the only one
     * is the bootstrap admin account that runs roles.sql.
     *
     * @return list<string> roles other than $allowedRole that bypass row-level security
     */
    public function rolesBypassingRowLevelSecurityOtherThan(string $allowedRole): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            'SELECT rolname FROM pg_roles WHERE rolbypassrls AND NOT rolsuper AND rolname <> ? ORDER BY 1',
            [$allowedRole],
        ));
    }

    /**
     * Only the resolver functions may run with their owner's rights, and pinround_resolver owns nothing else.
     * Their search_path, inputs, outputs and EXECUTE rights are ResolverFunctionTest's job.
     *
     * @return list<string> SECURITY DEFINER functions that are not exactly
     *                      ResolverFunctions::NAMES in public, one each, owned by pinround_resolver, and functions
     *                      that pinround_resolver owns but NAMES does not list; in any schema but the system ones
     */
    public function securityDefinerFunctionsOutsideTheResolvers(): array
    {
        $violations = [];
        $securityDefinerCounts = array_fill_keys(ResolverFunctions::NAMES, 0);
        foreach ($this->connection->fetchAllAssociative(
            "SELECT p.oid::regprocedure::text AS signature, p.proname AS name, p.prosecdef AS is_security_definer,
                    p.pronamespace = 'public'::regnamespace AS is_in_public, p.proowner = 'pinround_resolver'::regrole AS is_resolver_owned
             FROM pg_proc p
             WHERE p.prosecdef AND p.pronamespace IN ".self::USER_SCHEMAS." OR p.proowner = 'pinround_resolver'::regrole
             ORDER BY 1",
        ) as $function) {
            $isListed = true === $function['is_in_public'] && \in_array($function['name'], ResolverFunctions::NAMES, true);
            $isSecurityDefiner = true === $function['is_security_definer'];
            if ($isSecurityDefiner && $isListed) {
                ++$securityDefinerCounts[self::text($function['name'])];
            }
            if ($isSecurityDefiner && !$isListed) {
                $violations[] = self::text($function['signature']).' is SECURITY DEFINER but not in ResolverFunctions::NAMES';
            }
            if (true === $function['is_resolver_owned'] && !$isListed) {
                $violations[] = self::text($function['signature']).' is owned by pinround_resolver but not in ResolverFunctions::NAMES';
            }
            if (true !== $function['is_resolver_owned'] && $isListed) {
                $violations[] = self::text($function['signature']).' is not owned by pinround_resolver';
            }
        }
        foreach ($securityDefinerCounts as $name => $count) {
            if (1 !== $count) {
                $violations[] = \sprintf('%s: %d SECURITY DEFINER functions in public, expected 1', $name, $count);
            }
        }

        return $violations;
    }

    /**
     * @return list<string> rights of pinround_resolver beyond column SELECT grants on tables with a resolver_read
     *                      policy: table-wide privileges, default privileges, and column grants anywhere else
     */
    public function resolverRightsBeyondItsColumnReads(): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            "SELECT c.oid::regclass::text || ': ' || acl.privilege_type || ' on the whole table'
             FROM pg_class c, aclexplode(c.relacl) acl
             WHERE acl.grantee = 'pinround_resolver'::regrole
             UNION ALL
             SELECT 'default privilege of ' || d.defaclrole::regrole::text || ': ' || acl.privilege_type || ' on new objects of type ' || d.defaclobjtype::text
             FROM pg_default_acl d, aclexplode(d.defaclacl) acl
             WHERE acl.grantee = 'pinround_resolver'::regrole
             UNION ALL
             SELECT a.attrelid::regclass::text || ': ' || acl.privilege_type || ' (' || a.attname || ')'
             FROM pg_attribute a, aclexplode(a.attacl) acl
             WHERE acl.grantee = 'pinround_resolver'::regrole
               AND (acl.privilege_type <> 'SELECT'
                    OR NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = a.attrelid AND p.polname = 'resolver_read'))
             ORDER BY 1",
        ));
    }

    /**
     * The definition is pg_get_functiondef(), so a BEGIN ATOMIC body (kept in prosqlbody, with prosrc empty) is read
     * too. A reader keeps to the tenant when it takes p_organization_id as an input and its definition, without its
     * comments, compares that input with app.org_id. This is a text heuristic: a table named only in dynamic SQL, or a
     * binding that never filters a row, fools it, so review of each resolver function must back it up.
     *
     * @return array<string, array<string, bool>> table name => name of a resolver function (see RESOLVER_FUNCTIONS)
     *                                            whose definition names the table => whether it keeps to the tenant
     *                                            of the transaction; overloads of one name keep to it only if all do
     */
    public function resolverReadersByTable(): array
    {
        $readersByTable = [];
        foreach ($this->connection->fetchAllAssociative(
            self::RESOLVER_FUNCTIONS.", resolver_definition AS MATERIALIZED (
                SELECT f.proname, pg_get_functiondef(f.oid) AS definition,
                       EXISTS (
                           SELECT 1 FROM unnest(f.proargnames) WITH ORDINALITY AS arg(name, position)
                           WHERE arg.name = 'p_organization_id' AND COALESCE(f.proargmodes[arg.position], 'i') IN ('i', 'b')
                       ) AS takes_organization_id
                FROM resolver_function f
                WHERE f.prokind <> 'a'
             )
             SELECT c.relname AS table_name, d.proname AS function_name, d.definition, d.takes_organization_id
             FROM resolver_definition d JOIN pg_class c ON d.definition ~ ('\\m' || c.relname || '\\M')
             WHERE c.relkind IN ('r', 'p') AND c.relnamespace IN ".self::USER_SCHEMAS.'
             ORDER BY 1, 2',
        ) as $reader) {
            $isTenantBound = true === $reader['takes_organization_id']
                && 1 === preg_match(self::TENANT_BINDING, self::withoutSqlComments(self::text($reader['definition'])));
            $table = self::text($reader['table_name']);
            $function = self::text($reader['function_name']);
            $readersByTable[$table][$function] = ($readersByTable[$table][$function] ?? true) && $isTenantBound;
        }

        return $readersByTable;
    }

    /**
     * A text column pinround_resolver may read on a resolver_read table lets a resolver read it in every organization,
     * so it must hold no tenant's text: a validated CHECK on that column alone limits it to a fixed set of values, or
     * every resolver function whose definition names the table keeps to the transaction's tenant (see
     * resolverReadersByTable(), and resolverFunctionsInALanguageOtherThan() for definitions that are not SQL text).
     * Text is any type but boolean, number, date, time, interval, enum, uuid and bytea (a hash matched by exact key),
     * or an array of those, so a type nobody thought of counts as text.
     *
     * @return list<string> "table.column (type): why", for each such column that breaks the rule
     */
    public function resolverTextColumnsWithoutAClosedSetOrATenantBoundReader(): array
    {
        $readersByTable = $this->resolverReadersByTable();

        $checksByColumn = [];
        foreach ($this->connection->fetchAllAssociative(
            self::RESOLVER_TEXT_COLUMNS." SELECT a.attrelid::regclass::text || '.' || a.attname AS column_key, pg_get_constraintdef(k.oid) AS definition
             FROM resolver_text_column a JOIN pg_constraint k
               ON k.conrelid = a.attrelid AND k.contype = 'c' AND k.convalidated AND k.conkey = ARRAY[a.attnum]",
        ) as $check) {
            $checksByColumn[self::text($check['column_key'])][] = self::text($check['definition']);
        }

        $violations = [];
        foreach ($this->connection->fetchAllAssociative(
            self::RESOLVER_TEXT_COLUMNS.' SELECT c.oid::regclass::text AS table_name, c.relname, a.attname AS column_name,
                    quote_ident(a.attname) AS quoted_column, format_type(a.atttypid, a.atttypmod) AS type_name
             FROM resolver_text_column a JOIN pg_class c ON c.oid = a.attrelid
             ORDER BY 1, 3',
        ) as $column) {
            $tableName = self::text($column['table_name']);
            $closedSet = \sprintf(self::CLOSED_SET_CHECK, preg_quote(self::text($column['quoted_column']), '/'));
            if ([] !== preg_grep($closedSet, $checksByColumn[$tableName.'.'.self::text($column['column_name'])] ?? [])) {
                continue;
            }
            $readers = $readersByTable[self::text($column['relname'])] ?? [];
            $unboundReaders = array_keys(array_filter($readers, static fn (bool $isTenantBound): bool => !$isTenantBound));
            $why = match (true) {
                [] === $readers => 'no resolver function reads '.self::text($column['relname']),
                [] !== $unboundReaders => implode(', ', $unboundReaders).' read '.self::text($column['relname']).' without keeping to the tenant of the transaction',
                default => null,
            };
            if (null !== $why) {
                $violations[] = \sprintf('%s.%s (%s): no CHECK to a fixed set of values, and %s', $tableName, self::text($column['column_name']), self::text($column['type_name']), $why);
            }
        }

        return $violations;
    }

    /**
     * resolverReadersByTable() reads definitions as SQL text, which a body in another language need not be. Functions
     * in an untrusted language such as C count only when pinround_resolver owns them: only a superuser or a trusted
     * extension's script can create one, like the C functions pg_trgm and its kind bring.
     *
     * @param non-empty-list<string> $languages the languages whose definitions are SQL text
     *
     * @return list<string> "signature is in language name", for each function pinround_resolver owns, or may run in a
     *                      user schema, written in a language not in $languages
     */
    public function resolverFunctionsInALanguageOtherThan(array $languages): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            self::RESOLVER_FUNCTIONS." SELECT f.oid::regprocedure::text || ' is in language ' || l.lanname
             FROM resolver_function f JOIN pg_language l ON l.oid = f.prolang
             WHERE l.lanname NOT IN (?) AND (f.is_resolver_owned OR l.lanpltrusted)
             ORDER BY 1",
            [$languages],
            [ArrayParameterType::STRING],
        ));
    }

    /**
     * @return list<string> roles $member is a member of; for pinround_app any would lend it that role's rights or policies
     */
    public function rolesGrantedTo(string $member): array
    {
        return self::strings($this->connection->fetchFirstColumn(
            'SELECT m.roleid::regrole::text FROM pg_auth_members m WHERE m.member = ?::regrole ORDER BY 1',
            [$member],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function policiesOnTenantTables(Connection $connection): array
    {
        return $connection->fetchAllAssociative(
            self::TENANT_TABLES." SELECT c.oid::regclass::text AS table_name, pol.polname AS name,
                    CASE WHEN pol.polpermissive THEN 'PERMISSIVE' ELSE 'RESTRICTIVE' END AS permissive,
                    pol.polcmd::text AS command,
                    ARRAY(SELECT CASE WHEN r = 0 THEN 'PUBLIC' ELSE r::regrole::text END FROM unnest(pol.polroles) AS r ORDER BY 1)::text AS roles,
                    pg_get_expr(pol.polqual, pol.polrelid) AS using_expression,
                    pg_get_expr(pol.polwithcheck, pol.polrelid) AS check_expression
             FROM tenant_table t JOIN pg_class c ON c.oid = t.oid JOIN pg_policy pol ON pol.polrelid = c.oid
             ORDER BY 1, 2",
        );
    }

    /**
     * @param array<string, mixed> $policy
     *
     * @return array<string, mixed> the policy without its table, so policies of different tables compare equal
     */
    private static function shapeOf(array $policy): array
    {
        unset($policy['table_name']);

        return $policy;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_map(self::text(...), $values);
    }

    /**
     * Each comment becomes a space. A comment marker inside a string literal strips more than a comment, which errs
     * toward hiding a binding, so toward reporting the reader.
     */
    private static function withoutSqlComments(string $sql): string
    {
        return preg_replace(self::SQL_COMMENT, ' ', $sql)
            ?? throw new \UnexpectedValueException('A function definition could not be stripped of its comments.');
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('The catalog returned a non-text name.');
    }
}
