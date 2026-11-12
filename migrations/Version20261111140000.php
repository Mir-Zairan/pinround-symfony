<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * tkt_ticket_event, a ticket's append-only history partitioned by UTC month, and the foreign key from tkt_ticket to its
 * project. No entity maps tkt_ticket_event, so the schema_filter hides it and its partitions; the key of tkt_ticket is
 * in the ORM schema through TicketProjectKeyInSchema.
 */
final class Version20261111140000 extends AbstractMigration
{
    // Months after the current one. AddTicketEventPartitions keeps three ahead from then on.
    private const int MONTHS_AHEAD = 2;

    public function getDescription(): string
    {
        return 'Create tkt_ticket_event partitioned by month with row-level security; link tkt_ticket to tkt_project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE tkt_ticket ADD CONSTRAINT tkt_ticket_project_fkey'
            .' FOREIGN KEY (organization_id, project_id) REFERENCES tkt_project (organization_id, id)',
        );

        $this->addSql(
            'CREATE TABLE tkt_ticket_event (id UUID NOT NULL, organization_id UUID NOT NULL, ticket_id UUID NOT NULL,'
            .' actor_id UUID DEFAULT NULL, type TEXT NOT NULL, from_step UUID DEFAULT NULL, to_step UUID DEFAULT NULL,'
            .' payload JSONB NOT NULL, at TIMESTAMP WITH TIME ZONE NOT NULL, PRIMARY KEY (id, at),'
            ." CONSTRAINT tkt_ticket_event_payload_check CHECK (jsonb_typeof(payload) = 'object'),"
            .' CONSTRAINT tkt_ticket_event_ticket_fkey FOREIGN KEY (organization_id, ticket_id) REFERENCES tkt_ticket (organization_id, id) ON DELETE CASCADE)'
            .' PARTITION BY RANGE (at)',
        );
        $this->addSql('CREATE INDEX tkt_ticket_event_organization_id_ticket_id_at_idx ON tkt_ticket_event (organization_id, ticket_id, at DESC)');
        foreach (TenantTable::enableRls(TicketEventPartitions::PARENT) as $statement) {
            $this->addSql($statement);
        }
        // Append-only for the app: history is never changed or removed by it.
        $this->addSql('GRANT SELECT, INSERT ON tkt_ticket_event TO pinround_app');
        $this->addSql('GRANT SELECT ON tkt_ticket_event TO pinround_readonly');

        // From the wall clock, unlike aud_event: a database migrated years from now still starts with this month. No
        // DEFAULT partition, so a row past the last month fails instead of piling up where retention cannot drop it.
        $month = TicketEventPartitions::monthOf(new \DateTimeImmutable('now'));
        for ($i = 0; $i <= self::MONTHS_AHEAD; ++$i) {
            foreach (TicketEventPartitions::statementsFor($month) as $statement) {
                $this->addSql($statement);
            }
            $month = $month->modify('first day of next month');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tkt_ticket_event');
        $this->addSql('ALTER TABLE tkt_ticket DROP CONSTRAINT tkt_ticket_project_fkey');
    }
}
