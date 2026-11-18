<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * idn_api_token, the personal access tokens of P09.T1 (ADR-0021), a tenant table with row-level security that keeps
 * only the peppered hash of each token. The app may change only the revocation and the last use, so a token's scopes,
 * owner and expiry stay as issued. Then resolve_api_token gets its body: the ids of the token with that hash that is
 * neither revoked nor expired, read through resolver_read.
 */
final class Version20261119100000 extends AbstractMigration
{
    private const string RESOLVE_API_TOKEN_HEAD = 'CREATE OR REPLACE FUNCTION public.resolve_api_token(p_token_hash bytea)'
        .' RETURNS TABLE (token_id uuid, organization_id uuid, user_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
        .' SET search_path = pg_catalog, public, pg_temp AS $resolver$ %s $resolver$';

    public function getDescription(): string
    {
        return 'Create idn_api_token with row-level security and give resolve_api_token its body';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_api_token (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, name TEXT NOT NULL,'
            .' token_hash BYTEA NOT NULL, prefix TEXT NOT NULL, scopes TEXT[] NOT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT idn_api_token_name_check CHECK (char_length(name) BETWEEN 1 AND 100),'
            .' CONSTRAINT idn_api_token_token_hash_check CHECK (octet_length(token_hash) = 32),'
            // In a PostgreSQL regex "$" matches only at the end of the string.
            ." CONSTRAINT idn_api_token_prefix_check CHECK (prefix ~ '^pr_[A-Za-z0-9_-]{8}$'),"
            // ApiTokenScope's cases.
            ." CONSTRAINT idn_api_token_scopes_check CHECK (cardinality(scopes) >= 1 AND scopes <@ ARRAY['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write']::text[]),"
            // At most ApiToken::MAX_LIFETIME_DAYS; in hours, so the session's time zone cannot stretch a day.
            ." CONSTRAINT idn_api_token_expires_at_check CHECK (expires_at > created_at AND expires_at <= created_at + interval '8760 hours'))",
        );
        $this->addSql('CREATE INDEX idn_api_token_user_id_created_at_idx ON idn_api_token (organization_id, user_id, created_at)');
        // resolve_api_token runs before any tenant is known, so this index does not start with organization_id.
        $this->addSql('CREATE UNIQUE INDEX idn_api_token_token_hash_key ON idn_api_token (token_hash)');

        foreach (TenantTable::enableRls('idn_api_token') as $statement) {
            $this->addSql($statement);
        }
        // No DELETE: a revoked token keeps its row (the retention job purges, P11).
        $this->addSql('GRANT SELECT, INSERT ON idn_api_token TO pinround_app');
        $this->addSql('GRANT UPDATE (last_used_at, revoked_at) ON idn_api_token TO pinround_app');
        $this->addSql('GRANT SELECT ON idn_api_token TO pinround_readonly');
        foreach (TenantTable::allowResolverRead('idn_api_token', ['id', 'organization_id', 'user_id', 'token_hash', 'expires_at', 'revoked_at']) as $statement) {
            $this->addSql($statement);
        }

        $this->replaceResolveApiTokenBody(
            'SELECT t.id, t.organization_id, t.user_id FROM public.idn_api_token t'
            .' WHERE t.token_hash = p_token_hash AND t.revoked_at IS NULL AND t.expires_at > now()',
        );
    }

    public function down(Schema $schema): void
    {
        $this->replaceResolveApiTokenBody('SELECT NULL::uuid, NULL::uuid, NULL::uuid WHERE false');
        $this->addSql('DROP TABLE idn_api_token');
    }

    /**
     * As in Version20261109100000: only the owner may replace a function, so the migrator acts as pinround_resolver,
     * which needs CREATE on the schema for it and keeps none afterwards; every attribute is repeated, as CREATE OR
     * REPLACE resets the ones left out.
     */
    private function replaceResolveApiTokenBody(string $body): void
    {
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf(self::RESOLVE_API_TOKEN_HEAD, $body));
        $this->addSql('RESET ROLE');
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }
}
