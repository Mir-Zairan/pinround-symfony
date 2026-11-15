<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Migration;

use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;

/**
 * The row-level security every tenant table gets (ADR-0005), and the opt-in read a resolver function
 * needs before the tenant is known. Other table rights are not part of it: the migration that creates
 * the table grants pinround_app and pinround_readonly what they need.
 */
final class TenantTable
{
    // Up to 63 characters: PostgreSQL silently truncates longer names, which could hit another table.
    private const string PLAIN_IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}\z/';

    private const string SAME_TENANT = 'organization_id = '.TenantSetting::CURRENT_ORG_SQL;

    /**
     * @return list<string> statements for a migration's addSql(), in order
     *
     * @throws \InvalidArgumentException when $table is not a plain lowercase identifier of at most 63 characters
     */
    public static function enableRls(string $table): array
    {
        $quotedTable = self::quote($table, 'table');

        return [
            \sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $quotedTable),
            \sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $quotedTable),
            \sprintf('CREATE POLICY tenant_isolation ON %s FOR ALL USING (%s) WITH CHECK (%s)', $quotedTable, self::SAME_TENANT, self::SAME_TENANT),
        ];
    }

    /**
     * Lets the resolver functions (owned by pinround_resolver, see ResolverFunctions) read rows of every
     * organization in $table, but only $columns. Pass only the columns a resolver returns or matches on, never
     * secrets. A text column may be granted only to match on, never to return, and only when a CHECK limits it to a fixed
     * set of values (ntf_notification.kind), or for matching inside a function that limits itself to the transaction's
     * tenant (p_organization_id must equal app.org_id; search_ticket_ids on tkt_ticket.title, key and search). The
     * grant is per role, not per function, so the second case holds only while every resolver function that reads the
     * table is tenant-bound; CatalogTest fails otherwise. For a partitioned table pass the parent only, never a child
     * partition.
     *
     * @param list<string> $columns
     *
     * @return list<string> statements for a migration's addSql(), in order, after enableRls()
     *
     * @throws \InvalidArgumentException when $columns is empty, or $table or a column is not a plain lowercase identifier of at most 63 characters
     */
    public static function allowResolverRead(string $table, array $columns): array
    {
        $quotedTable = self::quote($table, 'table');
        if ([] === $columns) {
            throw new \InvalidArgumentException(\sprintf('The resolver read on "%s" needs at least one column.', $table));
        }
        $quotedColumns = array_map(static fn (string $column): string => self::quote($column, 'column'), $columns);

        return [
            \sprintf('CREATE POLICY resolver_read ON %s FOR SELECT TO pinround_resolver USING (true)', $quotedTable),
            \sprintf('GRANT SELECT (%s) ON %s TO pinround_resolver', implode(', ', $quotedColumns), $quotedTable),
        ];
    }

    private static function quote(string $identifier, string $kind): string
    {
        if (1 !== preg_match(self::PLAIN_IDENTIFIER, $identifier)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain lowercase %s name.', $identifier, $kind));
        }

        return '"'.$identifier.'"';
    }
}
