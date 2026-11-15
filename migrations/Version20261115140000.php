<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * organizations_with_due_notifications, the resolver function the release after quiet hours starts from
 * (ReleaseHeldNotifications): every 5 minutes it runs with no tenant set, so row-level security would show it no
 * notification. It returns the ids of the organizations with a notification held until p_now or earlier, each once,
 * and nothing else; the per-organization message then releases them under that organization's policy. It reads
 * ntf_notification through resolver_read, on the parent only, and only organization_id and deliver_after, which the
 * partial index (organization_id, deliver_after) WHERE deliver_after IS NOT NULL serves.
 */
final class Version20261115140000 extends AbstractMigration
{
    private const string SIGNATURE = 'public.organizations_with_due_notifications(timestamptz)';

    private const array READ_COLUMNS = ['organization_id', 'deliver_after'];

    public function getDescription(): string
    {
        return 'Create organizations_with_due_notifications, the resolver the release of held notifications starts from';
    }

    public function up(Schema $schema): void
    {
        foreach (TenantTable::allowResolverRead('ntf_notification', self::READ_COLUMNS) as $sql) {
            $this->addSql($sql);
        }

        // RETURNS TABLE, not SETOF uuid, so the output column is named as ResolverFunctionTest reads every resolver's.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.organizations_with_due_notifications(p_now timestamptz)
            RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp AS $resolver$
            SELECT DISTINCT n.organization_id
            FROM public.ntf_notification n
            WHERE n.deliver_after <= p_now
            ORDER BY n.organization_id
            $resolver$
            SQL);
        // Before the owner change, as in Version20261106140000: the migrator does not inherit pinround_resolver's rights.
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
        $this->addSql('DROP POLICY resolver_read ON ntf_notification');
        $this->addSql(\sprintf('REVOKE SELECT (%s) ON ntf_notification FROM pinround_resolver', implode(', ', self::READ_COLUMNS)));
    }
}
