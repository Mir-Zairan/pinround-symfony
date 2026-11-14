<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Notification\Infrastructure\Doctrine\NotificationPartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Notification tables, all tenant tables with row-level security: ntf_ping (mapped by Ping), ntf_notification
 * partitioned by UTC month of created_at, ntf_mute and ntf_delivery. Rows about a ticket go with it (ON DELETE CASCADE),
 * so the retention purge removes them with the ticket; each such key has an index led by (organization_id, ticket_id).
 * No entity maps the last three, so the schema_filter hides them and the partitions. The index names leave out the
 * leading organization_id, as tkt_ticket's do.
 */
final class Version20261114130000 extends AbstractMigration
{
    // Fixed, like tkt_ticket_event's, so every database migrated before 2027-11 has the same months.
    private const string FIRST_MONTH = '2026-11-01';
    private const string LAST_MONTH = '2027-12-01';
    // A database migrated later still gets its current month and the next two.
    private const int MONTHS_AHEAD = 2;

    public function getDescription(): string
    {
        return 'Create ntf_ping, ntf_notification partitioned by month, ntf_mute and ntf_delivery with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->createPings();
        $this->createNotifications();
        $this->createMutes();
        $this->createDeliveries();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ntf_delivery');
        $this->addSql('DROP TABLE ntf_mute');
        $this->addSql('DROP TABLE ntf_notification');
        $this->addSql('DROP TABLE ntf_ping');
    }

    private function createPings(): void
    {
        $this->addSql(
            'CREATE TABLE ntf_ping (organization_id UUID NOT NULL, id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' from_id UUID NOT NULL, to_id UUID NOT NULL, body TEXT NOT NULL,'
            .' deliver_after TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' cleared_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, cleared_reason TEXT DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // Ping::MAX_BODY_LENGTH, counted in characters as mb_strlen counts them.
            .' CONSTRAINT ntf_ping_body_check CHECK (char_length(body) BETWEEN 1 AND 500),'
            ." CONSTRAINT ntf_ping_cleared_reason_check CHECK (cleared_reason IN ('done', 'acted')),"
            .' CONSTRAINT ntf_ping_cleared_check CHECK ((cleared_at IS NULL) = (cleared_reason IS NULL)),'
            .' CONSTRAINT ntf_ping_ticket_fkey FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE INDEX ntf_ping_from_id_to_id_ticket_id_created_at_idx ON ntf_ping (organization_id, from_id, to_id, ticket_id, created_at)');
        $this->addSql('CREATE INDEX ntf_ping_to_id_ticket_id_open_idx ON ntf_ping (organization_id, to_id, ticket_id) WHERE cleared_at IS NULL');
        $this->addSql('CREATE INDEX ntf_ping_ticket_id_idx ON ntf_ping (organization_id, ticket_id)');
        foreach (TenantTable::enableRls('ntf_ping') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: a ping is cleared, never removed, so the rate limit's window still sees it.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON ntf_ping TO pinround_app');
        $this->addSql('GRANT SELECT ON ntf_ping TO pinround_readonly');
    }

    private function createNotifications(): void
    {
        $this->addSql(
            'CREATE TABLE ntf_notification (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL,'
            .' kind TEXT NOT NULL, actor_id UUID DEFAULT NULL, ticket_id UUID DEFAULT NULL, data JSONB NOT NULL,'
            .' deliver_after TIMESTAMP WITH TIME ZONE DEFAULT NULL, read_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,'
            .' done_at TIMESTAMP WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            // A partitioned table's key must contain the partition column.
            .' PRIMARY KEY (organization_id, id, created_at),'
            ." CONSTRAINT ntf_notification_data_check CHECK (jsonb_typeof(data) = 'object'),"
            .' CONSTRAINT ntf_notification_ticket_fkey FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)'
            .' PARTITION BY RANGE (created_at)',
        );
        // The bell groups a person's notifications that are not done by actor and ticket, newest first in each group.
        $this->addSql(
            'CREATE INDEX ntf_notification_user_id_actor_id_ticket_id_created_at_idx'
            .' ON ntf_notification (organization_id, user_id, actor_id, ticket_id, created_at DESC) WHERE done_at IS NULL',
        );
        // The digest: a person's unread notifications in time order.
        $this->addSql('CREATE INDEX ntf_notification_user_id_created_at_unread_idx ON ntf_notification (organization_id, user_id, created_at) WHERE read_at IS NULL');
        // Release after quiet hours: the notifications held until deliver_after.
        $this->addSql('CREATE INDEX ntf_notification_deliver_after_idx ON ntf_notification (organization_id, deliver_after) WHERE deliver_after IS NOT NULL');
        $this->addSql('CREATE INDEX ntf_notification_ticket_id_idx ON ntf_notification (organization_id, ticket_id) WHERE ticket_id IS NOT NULL');
        foreach (TenantTable::enableRls(NotificationPartitions::PARENT) as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: notifications leave when the retention job drops their month's partition (90 days).
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON ntf_notification TO pinround_app');
        $this->addSql('GRANT SELECT ON ntf_notification TO pinround_readonly');

        // No DEFAULT partition, so a row past the last month fails instead of piling up where retention cannot drop it.
        $firstMonth = new \DateTimeImmutable(self::FIRST_MONTH, new \DateTimeZone('UTC'));
        $lastMonth = max(
            new \DateTimeImmutable(self::LAST_MONTH, new \DateTimeZone('UTC')),
            NotificationPartitions::monthOf(new \DateTimeImmutable('now'))->modify(\sprintf('+%d months', self::MONTHS_AHEAD)),
        );
        for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('first day of next month')) {
            foreach (NotificationPartitions::statementsFor($month) as $statement) {
                $this->addSql($statement);
            }
        }
    }

    private function createMutes(): void
    {
        $this->addSql(
            'CREATE TABLE ntf_mute (organization_id UUID NOT NULL, user_id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' created_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, user_id, ticket_id),'
            .' CONSTRAINT ntf_mute_ticket_fkey FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)',
        );
        $this->addSql('CREATE INDEX ntf_mute_ticket_id_idx ON ntf_mute (organization_id, ticket_id)');
        foreach (TenantTable::enableRls('ntf_mute') as $sql) {
            $this->addSql($sql);
        }
        // No UPDATE: a mute is there or not; unmuting removes it.
        $this->addSql('GRANT SELECT, INSERT, DELETE ON ntf_mute TO pinround_app');
        $this->addSql('GRANT SELECT ON ntf_mute TO pinround_readonly');
    }

    private function createDeliveries(): void
    {
        // One row per email sent to a person for a window, such as a digest's day, so a second run sends nothing.
        $this->addSql(
            'CREATE TABLE ntf_delivery (organization_id UUID NOT NULL, user_id UUID NOT NULL, window_key TEXT NOT NULL,'
            .' sent_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, user_id, window_key),'
            ." CONSTRAINT ntf_delivery_window_key_check CHECK (window_key <> ''))",
        );
        foreach (TenantTable::enableRls('ntf_delivery') as $sql) {
            $this->addSql($sql);
        }
        // Append-only for the app: a delivery once recorded stays recorded.
        $this->addSql('GRANT SELECT, INSERT ON ntf_delivery TO pinround_app');
        $this->addSql('GRANT SELECT ON ntf_delivery TO pinround_readonly');
    }
}
