<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * idn_oauth_refresh_token, the OAuth refresh tokens of ADR-0050: a tenant table with row-level security that keeps
 * only the peppered hash of each token. Every token of one grant shares a family_id, so a reused token can revoke them
 * all (RFC 9700), and names the access token issued with it. The app reads and adds tokens and may change only used_at
 * and revoked_at; no DELETE (the retention job purges, P11), and pinround_readonly gets nothing. resolve_oauth_refresh_token
 * finds a token's organization by its hash before the tenant is known, so the token endpoint can rotate it under that
 * tenant; it finds a used token too, as only then can its reuse be told apart from an unknown token.
 */
final class Version20261121230000 extends AbstractMigration
{
    private const string SIGNATURE = 'public.resolve_oauth_refresh_token(bytea)';

    public function getDescription(): string
    {
        return 'Create idn_oauth_refresh_token with row-level security and resolve_oauth_refresh_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_oauth_refresh_token (organization_id UUID NOT NULL, id UUID NOT NULL, family_id UUID NOT NULL,'
            .' user_id UUID NOT NULL, client_id TEXT NOT NULL, scopes TEXT[] NOT NULL, token_hash BYTEA NOT NULL,'
            .' access_token_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT idn_oauth_refresh_token_token_hash_check CHECK (octet_length(token_hash) = 32),'
            // In a PostgreSQL regex "$" matches only at the end of the string. The shape of idn_oauth_client.client_id.
            ." CONSTRAINT idn_oauth_refresh_token_client_id_check CHECK (client_id ~ '^[A-Za-z0-9_-]{32}$'),"
            // ApiTokenScope's cases, as idn_api_token_scopes_check.
            ." CONSTRAINT idn_oauth_refresh_token_scopes_check CHECK (cardinality(scopes) >= 1 AND scopes <@ ARRAY['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write', 'notebooks:write']::text[]),"
            // OAuthRefreshToken::LIFETIME_DAYS.
            ." CONSTRAINT idn_oauth_refresh_token_expires_at_check CHECK (expires_at > created_at AND expires_at <= created_at + interval '30 days'))",
        );
        // The resolver looks a token up by its hash before the organization is known, so this index does not start with
        // organization_id; unique across organizations, so one hash can never name two.
        $this->addSql('CREATE UNIQUE INDEX idn_oauth_refresh_token_token_hash_key ON idn_oauth_refresh_token (token_hash)');
        // A reused token revokes its family; a revoked access token is looked up by its id.
        $this->addSql('CREATE INDEX idn_oauth_refresh_token_family_id_idx ON idn_oauth_refresh_token (organization_id, family_id)');
        $this->addSql('CREATE INDEX idn_oauth_refresh_token_access_token_id_idx ON idn_oauth_refresh_token (organization_id, access_token_id)');

        foreach (TenantTable::enableRls('idn_oauth_refresh_token') as $statement) {
            $this->addSql($statement);
        }
        $this->addSql('GRANT SELECT, INSERT ON idn_oauth_refresh_token TO pinround_app');
        $this->addSql('GRANT UPDATE (used_at, revoked_at) ON idn_oauth_refresh_token TO pinround_app');
        foreach (TenantTable::allowResolverRead('idn_oauth_refresh_token', ['organization_id', 'user_id', 'token_hash', 'expires_at', 'revoked_at']) as $statement) {
            $this->addSql($statement);
        }

        // A leaver's refresh token stops as their codes and access tokens do (resolve_oauth_authorization_code).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.resolve_oauth_refresh_token(p_token_hash bytea)
            RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp AS $resolver$
            SELECT t.organization_id FROM public.idn_oauth_refresh_token t
            WHERE t.token_hash = p_token_hash AND t.revoked_at IS NULL AND t.expires_at > now()
            AND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = t.organization_id AND m.user_id = t.user_id)
            $resolver$
            SQL);
        // Before the owner change, as in Version20261121220000: the migrator does not inherit pinround_resolver's rights.
        $this->addSql(\sprintf('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC', self::SIGNATURE));
        $this->addSql(\sprintf('GRANT EXECUTE ON FUNCTION %s TO pinround_app', self::SIGNATURE));
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql(\sprintf('ALTER FUNCTION %s OWNER TO pinround_resolver', self::SIGNATURE));
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf('DROP FUNCTION %s', self::SIGNATURE));
        $this->addSql('RESET ROLE');
        // Its policies and grants go with it.
        $this->addSql('DROP TABLE idn_oauth_refresh_token');
    }
}
