<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107170000 extends AbstractMigration
{
    // Fixed, not taken from the clock, so every database gets the same partitions. The monthly job that adds the
    // next ones does not exist yet (phase-00 hand-off notes).
    private const string FIRST_MONTH = '2026-11-01';
    private const int MONTHS = 14;

    public function getDescription(): string
    {
        return 'Create aud_event, the append-only audit log partitioned by month (ADR-0026)';
    }

    public function up(Schema $schema): void
    {
        // A token always acts for its user (idn_api_token.user_id), so a token row names both; a system row neither.
        $this->addSql(
            'CREATE TABLE aud_event (id UUID NOT NULL, organization_id UUID NOT NULL, at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' actor_kind TEXT NOT NULL, actor_user_id UUID DEFAULT NULL, actor_token_id UUID DEFAULT NULL,'
            .' action TEXT NOT NULL, target_type TEXT NOT NULL, target_id UUID DEFAULT NULL, data JSONB NOT NULL,'
            .' ip_hash BYTEA DEFAULT NULL, user_agent_hash BYTEA DEFAULT NULL, PRIMARY KEY (id, at),'
            ." CONSTRAINT aud_event_actor_check CHECK ((actor_kind = 'user' AND actor_user_id IS NOT NULL AND actor_token_id IS NULL)"
            ." OR (actor_kind = 'token' AND actor_user_id IS NOT NULL AND actor_token_id IS NOT NULL)"
            ." OR (actor_kind = 'system' AND actor_user_id IS NULL AND actor_token_id IS NULL)),"
            ." CONSTRAINT aud_event_data_check CHECK (jsonb_typeof(data) = 'object'))"
            .' PARTITION BY RANGE (at)',
        );
        $this->addSql('CREATE INDEX aud_event_organization_id_at_idx ON aud_event (organization_id, at DESC)');
        $this->addSql('CREATE INDEX aud_event_organization_id_target_idx ON aud_event (organization_id, target_type, target_id, at DESC)');
        foreach (TenantTable::enableRls('aud_event') as $statement) {
            $this->addSql($statement);
        }
        // Append-only for the app (ADR-0026); the retention job's role gets its rights with that job (P11).
        $this->addSql('GRANT SELECT, INSERT ON aud_event TO pinround_app');
        // Support answers "who changed this?" with the same tenant setting; the rows hold ids and settings, no content.
        $this->addSql('GRANT SELECT ON aud_event TO pinround_readonly');

        // No DEFAULT partition: retention drops whole months, and a row past the last month must fail, not pile up.
        $month = new \DateTimeImmutable(self::FIRST_MONTH, new \DateTimeZone('UTC'));
        for ($i = 0; $i < self::MONTHS; ++$i) {
            $nextMonth = $month->modify('first day of next month');
            $partition = 'aud_event_'.$month->format('Ym');
            // Explicit +00: the bounds are UTC months whatever the migrating session's time zone.
            $this->addSql(\sprintf(
                "CREATE TABLE %s PARTITION OF aud_event FOR VALUES FROM ('%s+00') TO ('%s+00')",
                $partition,
                $month->format('Y-m-d'),
                $nextMonth->format('Y-m-d'),
            ));
            // PostgreSQL copies neither the RLS flags nor the policy to a partition, and a direct query uses the partition's own.
            foreach (TenantTable::enableRls($partition) as $statement) {
                $this->addSql($statement);
            }
            $this->addSql(\sprintf('REVOKE ALL ON %s FROM PUBLIC, pinround_app, pinround_readonly', $partition));
            $month = $nextMonth;
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE aud_event');
    }
}
