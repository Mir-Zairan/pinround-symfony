<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The connected apps lists of ADR-0050 (OAuthConnections) read each refresh token family's newest token, the only one
 * neither traded nor revoked, of one person or of the whole organization. A family adds a token at every hourly
 * refresh, so without this partial index the lists would read every token the organization ever had. No grant
 * changes.
 */
final class Version20261122014100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the live refresh tokens of idn_oauth_refresh_token by person for the connected apps lists';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idn_oauth_refresh_token_user_id_live_idx ON idn_oauth_refresh_token (organization_id, user_id) WHERE ((revoked_at IS NULL) AND (used_at IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idn_oauth_refresh_token_user_id_live_idx');
    }
}
