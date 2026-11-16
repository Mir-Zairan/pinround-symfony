<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_check_item, one line of a ticket's checklist round per pin of its Round 0 (P05.T6), as a tenant table with
 * row-level security. DbalChecklists writes it and no entity maps it, so the schema_filter hides it. The CHECKs repeat
 * CheckItem's rules, as Version20261115180000's repeat Round's. A ticket gets one checklist round: the partial unique
 * index on rvw_round is the backstop for two requests that both find none and both add one.
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rvw_check_item with row-level security and allow one checklist round per ticket';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE rvw_check_item (organization_id UUID NOT NULL, id UUID NOT NULL, round_id UUID NOT NULL,'
            .' pin_id UUID NOT NULL, state TEXT NOT NULL, changed_by UUID DEFAULT NULL,'
            .' changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // The values of CheckItemState.
            ." CONSTRAINT rvw_check_item_state_check CHECK (state IN ('open', 'fixed', 'ok', 'not_fixed')),"
            // Who changed the state and when come together (CheckItem::markFixed and the others).
            .' CONSTRAINT rvw_check_item_changed_check CHECK ((changed_by IS NULL) = (changed_at IS NULL)),'
            .' CONSTRAINT rvw_check_item_round_fkey FOREIGN KEY (organization_id, round_id) REFERENCES rvw_round (organization_id, id) ON DELETE CASCADE,'
            // NO ACTION, checked at the end of the statement, so deleting a ticket's rounds at once takes the report's
            // pins and the checklist's items together; a pin alone is never removed from a shared report.
            .' CONSTRAINT rvw_check_item_pin_fkey FOREIGN KEY (organization_id, pin_id) REFERENCES rvw_pin (organization_id, id))',
        );
        $this->addSql('CREATE UNIQUE INDEX rvw_check_item_pin_id_key ON rvw_check_item (organization_id, pin_id)');
        $this->addSql('CREATE INDEX rvw_check_item_round_id_idx ON rvw_check_item (organization_id, round_id)');
        foreach (TenantTable::enableRls('rvw_check_item') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: an item goes only with its round. UPDATE: marking it fixed, OK or not fixed.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON rvw_check_item TO pinround_app');
        $this->addSql('GRANT SELECT ON rvw_check_item TO pinround_readonly');

        // The slot DbalChecklists::add takes with ON CONFLICT DO NOTHING.
        $this->addSql("CREATE UNIQUE INDEX rvw_round_ticket_id_checklist_key ON rvw_round (organization_id, ticket_id) WHERE kind = 'checklist'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX rvw_round_ticket_id_checklist_key');
        $this->addSql('DROP TABLE rvw_check_item');
    }
}
