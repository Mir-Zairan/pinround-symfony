<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The notebooks:write token scope (P13, ADR-0046): idn_api_token_scopes_check takes it beside ApiTokenScope's other
 * cases. The grants of Version20261119100000 stay as they are.
 */
final class Version20261120102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a personal access token hold the notebooks:write scope';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idn_api_token DROP CONSTRAINT idn_api_token_scopes_check');
        $this->addSql(
            'ALTER TABLE idn_api_token ADD CONSTRAINT idn_api_token_scopes_check'
            ." CHECK (cardinality(scopes) >= 1 AND scopes <@ ARRAY['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write', 'notebooks:write']::text[])",
        );
    }

    public function down(Schema $schema): void
    {
        // PostgreSQL refuses the narrower check while any token holds notebooks:write, so no token loses a scope quietly.
        $this->addSql('ALTER TABLE idn_api_token DROP CONSTRAINT idn_api_token_scopes_check');
        $this->addSql(
            'ALTER TABLE idn_api_token ADD CONSTRAINT idn_api_token_scopes_check'
            ." CHECK (cardinality(scopes) >= 1 AND scopes <@ ARRAY['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write']::text[])",
        );
    }
}
