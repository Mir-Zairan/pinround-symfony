<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * tkt_project, a tenant table with row-level security: the projects of an organization, each owned by one of its
 * teams. next_number is the number the next ticket of the project gets; DbalNextTicketNumber raises it. No foreign
 * key to org_team, a table of another module.
 */
final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tkt_project with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE tkt_project (organization_id UUID NOT NULL, id UUID NOT NULL, team_id UUID NOT NULL,'
            .' key TEXT NOT NULL, name TEXT NOT NULL, next_number INT NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // The same format as ProjectKey.
            ." CONSTRAINT tkt_project_key_check CHECK (key ~ '^[A-Z][A-Z0-9]{1,9}$'),"
            .' CONSTRAINT tkt_project_next_number_check CHECK (next_number >= 1))',
        );
        $this->addSql('CREATE UNIQUE INDEX tkt_project_organization_id_key_key ON tkt_project (organization_id, key)');
        $this->addSql('CREATE INDEX tkt_project_organization_id_team_id_idx ON tkt_project (organization_id, team_id)');

        foreach (TenantTable::enableRls('tkt_project') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT ON tkt_project TO pinround_readonly');
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON tkt_project TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tkt_project');
    }
}
