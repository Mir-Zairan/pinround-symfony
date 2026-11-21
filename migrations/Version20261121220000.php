<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * idn_oauth_authorization_code, the OAuth authorization codes of ADR-0050: a tenant table with row-level security that
 * keeps only the peppered hash of each code. The app reads and adds codes and may change only used_at, so a code's
 * client, redirect URI, challenge, scopes, person and expiry stay as issued; no DELETE (the retention job purges, P11),
 * and pinround_readonly gets nothing, as a code is of no use to support. resolve_oauth_authorization_code finds a
 * code's organization by its hash before the tenant is known, so the token endpoint can trade it under that tenant.
 */
final class Version20261121220000 extends AbstractMigration
{
    private const string SIGNATURE = 'public.resolve_oauth_authorization_code(bytea)';

    public function getDescription(): string
    {
        return 'Create idn_oauth_authorization_code with row-level security and resolve_oauth_authorization_code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_oauth_authorization_code (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL,'
            .' client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL, code_challenge TEXT NOT NULL, scopes TEXT[] NOT NULL,'
            .' code_hash BYTEA NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT idn_oauth_authorization_code_code_hash_check CHECK (octet_length(code_hash) = 32),'
            // In a PostgreSQL regex "$" matches only at the end of the string. The shape of idn_oauth_client.client_id.
            ." CONSTRAINT idn_oauth_authorization_code_client_id_check CHECK (client_id ~ '^[A-Za-z0-9_-]{32}$'),"
            // OAuthRedirectUri::MAX_LENGTH, in bytes as there.
            .' CONSTRAINT idn_oauth_authorization_code_redirect_uri_check CHECK (octet_length(redirect_uri) BETWEEN 1 AND 512),'
            // OAuthAuthorizationCode::CODE_CHALLENGE_SHAPE.
            ." CONSTRAINT idn_oauth_authorization_code_code_challenge_check CHECK (code_challenge ~ '^[A-Za-z0-9._~-]{43,128}$'),"
            // ApiTokenScope's cases, as idn_api_token_scopes_check.
            ." CONSTRAINT idn_oauth_authorization_code_scopes_check CHECK (cardinality(scopes) >= 1 AND scopes <@ ARRAY['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write', 'notebooks:write']::text[]),"
            // OAuthAuthorizationCode::MAX_LIFETIME_SECONDS.
            ." CONSTRAINT idn_oauth_authorization_code_expires_at_check CHECK (expires_at > created_at AND expires_at <= created_at + interval '60 seconds'))",
        );
        // The resolver looks a code up by its hash before the organization is known, so this index does not start with
        // organization_id; unique across organizations, so one hash can never name two.
        $this->addSql('CREATE UNIQUE INDEX idn_oauth_authorization_code_code_hash_key ON idn_oauth_authorization_code (code_hash)');

        foreach (TenantTable::enableRls('idn_oauth_authorization_code') as $statement) {
            $this->addSql($statement);
        }
        $this->addSql('GRANT SELECT, INSERT ON idn_oauth_authorization_code TO pinround_app');
        $this->addSql('GRANT UPDATE (used_at) ON idn_oauth_authorization_code TO pinround_app');
        foreach (TenantTable::allowResolverRead('idn_oauth_authorization_code', ['organization_id', 'user_id', 'code_hash', 'expires_at', 'used_at']) as $statement) {
            $this->addSql($statement);
        }

        // A leaver's code stops as their tokens do (resolve_api_token, Version20261121193200); org_membership is read
        // through the resolver_read policy and column grant of Version20261108140000.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.resolve_oauth_authorization_code(p_code_hash bytea)
            RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp AS $resolver$
            SELECT c.organization_id FROM public.idn_oauth_authorization_code c
            WHERE c.code_hash = p_code_hash AND c.used_at IS NULL AND c.expires_at > now()
            AND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = c.organization_id AND m.user_id = c.user_id)
            $resolver$
            SQL);
        // Before the owner change, as in Version20261119150000: the migrator does not inherit pinround_resolver's rights.
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
        $this->addSql('DROP TABLE idn_oauth_authorization_code');
    }
}
