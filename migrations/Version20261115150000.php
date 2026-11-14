<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * digest_recipients, the resolver function the email digest job starts from: every 15 minutes it runs with no tenant
 * set, so row-level security would show it no notification. It returns each (organization, person) pair with an
 * unread, undone, delivered ping or question about a ticket made an hour or more before p_now, the rows
 * DbalDigestItems lists, and nothing else; the per-person message then reads the digest under that organization's
 * policy. It reads the ntf_notification parent through the resolver_read policy Version20261115140000 created, so this
 * only grants the further columns it matches on or returns. kind is text, but it is matched only against the two
 * constants in the body, never against an input, so it cannot be probed for content.
 */
final class Version20261115150000 extends AbstractMigration
{
    private const string SIGNATURE = 'public.digest_recipients(timestamptz)';

    private const array READ_COLUMNS = ['user_id', 'kind', 'ticket_id', 'read_at', 'done_at', 'created_at'];

    public function getDescription(): string
    {
        return 'Create digest_recipients, the resolver the email digest job starts from, and its index';
    }

    public function up(Schema $schema): void
    {
        // The digest's own rows only, small next to the unread index; deliver_after rides along so the resolver's scan
        // needs no table read for it.
        $this->addSql(
            'CREATE INDEX ntf_notification_user_id_created_at_digest_idx ON ntf_notification (organization_id, user_id, created_at)'
            ." INCLUDE (deliver_after) WHERE read_at IS NULL AND done_at IS NULL AND ticket_id IS NOT NULL AND kind IN ('ping', 'question')",
        );
        $this->addSql(\sprintf('GRANT SELECT (%s) ON ntf_notification TO pinround_resolver', implode(', ', self::READ_COLUMNS)));

        // The hour is Digest::MIN_ITEM_AGE_SECONDS; a row exactly an hour old counts, as in DbalDigestItems.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION public.digest_recipients(p_now timestamptz)
            RETURNS TABLE (organization_id uuid, user_id uuid) LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, public, pg_temp AS $resolver$
            SELECT DISTINCT n.organization_id, n.user_id
            FROM public.ntf_notification n
            WHERE n.read_at IS NULL AND n.done_at IS NULL AND n.ticket_id IS NOT NULL AND n.kind IN ('ping', 'question')
              AND n.created_at <= p_now - interval '1 hour'
              AND (n.deliver_after IS NULL OR n.deliver_after <= p_now)
            ORDER BY n.organization_id, n.user_id
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
        $this->addSql(\sprintf('REVOKE SELECT (%s) ON ntf_notification FROM pinround_resolver', implode(', ', self::READ_COLUMNS)));
        $this->addSql('DROP INDEX ntf_notification_user_id_created_at_digest_idx');
    }
}
