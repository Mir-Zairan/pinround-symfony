<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * organizations_with_expiring_api_tokens leaves out a token that lives 8 days or less
 * (ApiToken::EXPIRY_WARNING_WINDOW_DAYS): with the 8-day range of Version20261119150000 it would be warned the day it
 * is made.
 * The resolver now reads created_at, so pinround_resolver gets that column too. As in Version20261119100000, only the
 * owner may replace a function, so the migrator acts as pinround_resolver, which needs CREATE on the schema for it and
 * keeps none afterwards; every attribute is repeated, as CREATE OR REPLACE resets the ones left out.
 */
final class Version20261119160000 extends AbstractMigration
{
    private const string CREATE_OR_REPLACE_FORMAT = <<<'SQL'
        CREATE OR REPLACE FUNCTION public.organizations_with_expiring_api_tokens(p_from timestamptz, p_before timestamptz)
        RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
        SET search_path = pg_catalog, public, pg_temp AS $resolver$
        SELECT DISTINCT t.organization_id
        FROM public.idn_api_token t
        WHERE t.revoked_at IS NULL AND t.expiry_warned_at IS NULL AND t.expires_at >= p_from AND t.expires_at < p_before%s
        ORDER BY t.organization_id
        $resolver$
        SQL;

    public function getDescription(): string
    {
        return 'Leave tokens that live 8 days or less out of organizations_with_expiring_api_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT SELECT (created_at) ON idn_api_token TO pinround_resolver');
        $this->replaceBody(\sprintf(self::CREATE_OR_REPLACE_FORMAT, " AND t.expires_at - t.created_at > interval '8 days'"));
    }

    public function down(Schema $schema): void
    {
        $this->replaceBody(\sprintf(self::CREATE_OR_REPLACE_FORMAT, ''));
        $this->addSql('REVOKE SELECT (created_at) ON idn_api_token FROM pinround_resolver');
    }

    private function replaceBody(string $createOrReplace): void
    {
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql($createOrReplace);
        $this->addSql('RESET ROLE');
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }
}
