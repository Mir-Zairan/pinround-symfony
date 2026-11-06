<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Migration;

use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;

/**
 * The row-level security every tenant table gets (ADR-0005). Table rights are not part of it: the
 * migration that creates the table grants pinround_app and pinround_readonly what they need.
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
        if (1 !== preg_match(self::PLAIN_IDENTIFIER, $table)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a plain lowercase table name.', $table));
        }

        $quotedTable = '"'.$table.'"';

        return [
            \sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $quotedTable),
            \sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $quotedTable),
            \sprintf('CREATE POLICY tenant_isolation ON %s FOR ALL USING (%s) WITH CHECK (%s)', $quotedTable, self::SAME_TENANT, self::SAME_TENANT),
        ];
    }
}
