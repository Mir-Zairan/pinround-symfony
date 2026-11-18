<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The 7-day expiry warning of API tokens (P09.T1): idn_api_token gets expiry_warned_at, which the warning job sets once
 * per token in one conditional UPDATE, so the app may change that column too. The partial index (organization_id,
 * expires_at, id) WHERE revoked_at IS NULL serves the job's range and the Admin list of live tokens
 * (ApiTokens::livePage), both ordered by expiry. organizations_with_expiring_api_tokens is the resolver the daily job
 * starts from with no tenant set: the ids of the organizations with a token that is not revoked, not yet warned and
 * expires in [p_from, p_before), each once. It reads idn_api_token through the resolver_read policy of
 * Version20261119100000, which needs only expiry_warned_at added to its columns.
 */
final class Version20261119150000 extends AbstractMigration
{
    private const string SIGNATURE = 'public.organizations_with_expiring_api_tokens(timestamptz, timestamptz)';

    public function getDescription(): string
    {
        return 'Add the expiry warning of API tokens and organizations_with_expiring_api_tokens, the resolver of its daily job';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idn_api_token ADD expiry_warned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idn_api_token_expires_at_id_live_idx ON idn_api_token (organization_id, expires_at, id) WHERE (revoked_at IS NULL)');
        $this->addSql('GRANT UPDATE (expiry_warned_at) ON idn_api_token TO pinround_app');
        $this->addSql('GRANT SELECT (expiry_warned_at) ON idn_api_token TO pinround_resolver');

        // RETURNS TABLE, not SETOF uuid, so the output column is named as ResolverFunctionTest reads every resolver's.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.organizations_with_expiring_api_tokens(p_from timestamptz, p_before timestamptz)
            RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp AS $resolver$
            SELECT DISTINCT t.organization_id
            FROM public.idn_api_token t
            WHERE t.revoked_at IS NULL AND t.expiry_warned_at IS NULL AND t.expires_at >= p_from AND t.expires_at < p_before
            ORDER BY t.organization_id
            $resolver$
            SQL);
        // Before the owner change, as in Version20261115140000: the migrator does not inherit pinround_resolver's rights.
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
        $this->addSql('DROP INDEX idn_api_token_expires_at_id_live_idx');
        // Its column rights go with it.
        $this->addSql('ALTER TABLE idn_api_token DROP expiry_warned_at');
    }
}
