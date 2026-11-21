<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * resolve_oauth_authorization_code finds a used code too, expired or not, until the retention job purges it, so the
 * token endpoint can tell a replayed code from an unknown one and revoke the tokens issued with it (RFC 6749 section
 * 4.1.2, ADR-0050); an unused code is still found only until it expires. The leaver check stays. used_at is among the
 * columns the resolver may read already (Version20261121220000).
 */
final class Version20261122000000 extends AbstractMigration
{
    private const string RESOLVER_HEAD = 'CREATE OR REPLACE FUNCTION public.resolve_oauth_authorization_code(p_code_hash bytea)'
        .' RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
        .' SET search_path = pg_catalog, public, pg_temp AS $resolver$ %s $resolver$';

    private const string MEMBER = "\nAND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = c.organization_id AND m.user_id = c.user_id)";

    public function getDescription(): string
    {
        return 'Let resolve_oauth_authorization_code find used codes, so a replayed code revokes its tokens';
    }

    public function up(Schema $schema): void
    {
        $this->replaceResolverBody(
            'SELECT c.organization_id FROM public.idn_oauth_authorization_code c'
            ."\nWHERE c.code_hash = p_code_hash AND (c.used_at IS NOT NULL OR c.expires_at > now())".self::MEMBER,
        );
    }

    public function down(Schema $schema): void
    {
        $this->replaceResolverBody(
            'SELECT c.organization_id FROM public.idn_oauth_authorization_code c'
            ."\nWHERE c.code_hash = p_code_hash AND c.used_at IS NULL AND c.expires_at > now()".self::MEMBER,
        );
    }

    /**
     * As in Version20261121193200: only the owner may replace a function, so the migrator acts as pinround_resolver,
     * which needs CREATE on the schema for it and keeps none afterwards; every attribute is repeated, as CREATE OR
     * REPLACE resets the ones left out.
     */
    private function replaceResolverBody(string $body): void
    {
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf(self::RESOLVER_HEAD, $body));
        $this->addSql('RESET ROLE');
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }
}
