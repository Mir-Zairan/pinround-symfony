<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every idn_oauth_refresh_token row keeps when its family's first token was issued (family_created_at), so the
 * connected apps lists of ADR-0050 read a connection's time and whether it was renewed from its live token alone. Read
 * from the family's other tokens, both grew slower with every hourly refresh and changed once the retention job purged
 * the traded ones. Two partial indexes give each list its live tokens newest connected first; they replace
 * idn_oauth_refresh_token_user_id_live_idx. The grants of Version20261121230000 already cover the new column. The table
 * comes in the same release as this migration and has no released rows yet, so the backfill, NOT NULL and the indexes
 * run here in one step, not by expand and contract (data-model.md, Migrations policy).
 */
final class Version20261122020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the family start on every refresh token of idn_oauth_refresh_token and index the live ones by it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idn_oauth_refresh_token ADD COLUMN family_created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // FORCE ROW LEVEL SECURITY hides every row from the owner with no tenant set, so it is lifted for the update only,
        // as in Version20261118140000; the migration's transaction restores it on failure.
        $this->addSql('ALTER TABLE idn_oauth_refresh_token NO FORCE ROW LEVEL SECURITY');
        $this->addSql(
            'UPDATE idn_oauth_refresh_token t SET family_created_at = family.first_created_at'
            .' FROM (SELECT organization_id, family_id, min(created_at) AS first_created_at FROM idn_oauth_refresh_token GROUP BY organization_id, family_id) family'
            .' WHERE t.organization_id = family.organization_id AND t.family_id = family.family_id',
        );
        $this->addSql('ALTER TABLE idn_oauth_refresh_token FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE idn_oauth_refresh_token ALTER COLUMN family_created_at SET NOT NULL');
        $this->addSql('ALTER TABLE idn_oauth_refresh_token ADD CONSTRAINT idn_oauth_refresh_token_family_created_at_check CHECK (family_created_at <= created_at)');

        $this->addSql('DROP INDEX idn_oauth_refresh_token_user_id_live_idx');
        $this->addSql(
            'CREATE INDEX idn_oauth_refresh_token_user_id_family_created_at_live_idx'
            .' ON idn_oauth_refresh_token (organization_id, user_id, family_created_at DESC, family_id DESC)'
            .' WHERE ((revoked_at IS NULL) AND (used_at IS NULL))',
        );
        $this->addSql(
            'CREATE INDEX idn_oauth_refresh_token_family_created_at_live_idx'
            .' ON idn_oauth_refresh_token (organization_id, family_created_at DESC, family_id DESC)'
            .' WHERE ((revoked_at IS NULL) AND (used_at IS NULL))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idn_oauth_refresh_token_family_created_at_live_idx');
        $this->addSql('DROP INDEX idn_oauth_refresh_token_user_id_family_created_at_live_idx');
        $this->addSql('CREATE INDEX idn_oauth_refresh_token_user_id_live_idx ON idn_oauth_refresh_token (organization_id, user_id) WHERE ((revoked_at IS NULL) AND (used_at IS NULL))');
        $this->addSql('ALTER TABLE idn_oauth_refresh_token DROP COLUMN family_created_at');
    }
}
