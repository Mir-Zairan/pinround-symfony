<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * resolve_api_token refuses the token of a leaver (ADR-0050, note of 2026-11-21): besides being neither revoked nor
 * expired, the token's person must still have their org_membership row in the token's organization. A person leaves
 * by that row being deleted, so every token they hold, personal or OAuth, stops at the next request and keeps its row.
 * The function reads org_membership through the resolver_read policy and column grant of Version20261108140000, and
 * the membership's primary key (organization_id, user_id) serves the lookup.
 */
final class Version20261121193200 extends AbstractMigration
{
    private const string RESOLVE_API_TOKEN_HEAD = 'CREATE OR REPLACE FUNCTION public.resolve_api_token(p_token_hash bytea)'
        .' RETURNS TABLE (token_id uuid, organization_id uuid, user_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
        .' SET search_path = pg_catalog, public, pg_temp AS $resolver$ %s $resolver$';

    private const string LIVE_TOKEN = 'SELECT t.id, t.organization_id, t.user_id FROM public.idn_api_token t'
        .' WHERE t.token_hash = p_token_hash AND t.revoked_at IS NULL AND t.expires_at > now()';

    public function getDescription(): string
    {
        return 'Let resolve_api_token find a token only while its person is a member of its organization';
    }

    public function up(Schema $schema): void
    {
        $this->replaceResolveApiTokenBody(
            self::LIVE_TOKEN
            .' AND EXISTS (SELECT 1 FROM public.org_membership m WHERE m.organization_id = t.organization_id AND m.user_id = t.user_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->replaceResolveApiTokenBody(self::LIVE_TOKEN);
    }

    /**
     * As in Version20261119100000: only the owner may replace a function, so the migrator acts as pinround_resolver,
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
