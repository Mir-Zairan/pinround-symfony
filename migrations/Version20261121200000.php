<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * idn_oauth_client, the OAuth clients that registered themselves (ADR-0050). A global table like idn_user
 * (data-model.md, Identity): a client registers before anyone logs in and serves people of every organization, so it has
 * no organization_id and no row-level security; the organization is bound per grant. It holds no secret: clients are
 * public. pinround_readonly gets nothing, and the app only reads and adds, as nothing changes or removes a client yet.
 */
final class Version20261121200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create idn_oauth_client, the self-registered public OAuth clients';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_oauth_client (client_id TEXT NOT NULL, client_name TEXT DEFAULT NULL, redirect_uris TEXT[] NOT NULL,'
            .' grant_types TEXT[] NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (client_id),'
            // In a PostgreSQL regex "$" matches only at the end of the string.
            ." CONSTRAINT idn_oauth_client_client_id_check CHECK (client_id ~ '^[A-Za-z0-9_-]{32}$'),"
            .' CONSTRAINT idn_oauth_client_client_name_check CHECK (char_length(client_name) BETWEEN 1 AND 100),'
            // OAuthClient::MAX_REDIRECT_URIS.
            .' CONSTRAINT idn_oauth_client_redirect_uris_check CHECK (cardinality(redirect_uris) BETWEEN 1 AND 5),'
            // OAuthGrantType's cases, authorization_code always among them.
            ." CONSTRAINT idn_oauth_client_grant_types_check CHECK ('authorization_code' = ANY (grant_types)"
            ." AND grant_types <@ ARRAY['authorization_code', 'refresh_token']::text[]))",
        );
        $this->addSql('GRANT SELECT, INSERT ON idn_oauth_client TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idn_oauth_client');
    }
}
