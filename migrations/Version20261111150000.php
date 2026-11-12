<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every monthly partition of tkt_ticket_event through 2027-12, as aud_event has, because AddTicketEventPartitions does
 * not run in prod (phase-03 hand-off notes). Adds only the months that are missing: Version20261111140000 made three
 * from its wall clock, and the job may have added more.
 */
final class Version20261111150000 extends AbstractMigration
{
    // Fixed, like aud_event's, so every database has the same months whenever it is migrated.
    private const string FIRST_MONTH = '2026-11-01';
    private const string LAST_MONTH = '2027-12-01';

    public function getDescription(): string
    {
        return 'Add the missing tkt_ticket_event partitions from 2026-11 through 2027-12';
    }

    public function up(Schema $schema): void
    {
        // Read inside the migration's transaction, under the job's lock, so the job cannot add a month before the CREATE.
        TicketEventPartitions::lockForChanges($this->connection);
        $missingMonths = TicketEventPartitions::monthsWithoutPartition(
            $this->connection,
            new \DateTimeImmutable(self::FIRST_MONTH, new \DateTimeZone('UTC')),
            new \DateTimeImmutable(self::LAST_MONTH, new \DateTimeZone('UTC')),
        );
        foreach ($missingMonths as $month) {
            foreach (TicketEventPartitions::statementsFor($month) as $statement) {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Keeps the partitions: they may hold events, and Version20261111140000's down drops them with the table.
    }
}
