<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a draft round needs once its owner edits it (P05.T8, ADR-0017): the hand-off decision (decided_at, decision),
 * the time its owner deleted it (deleted_at), and DELETE on the pictures and pins a draft drops. A deleted draft is
 * closed, so the one-open-draft slot now holds open unshared drafts only and its owner may start a new one. The CHECKs
 * repeat Round's rules, as Version20261115180000's do.
 */
final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the draft decision and deletion to rvw_round, hold only open drafts in its draft slot, and let the app delete screenshots and pins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE rvw_round ADD decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD decision TEXT DEFAULT NULL,'
            .' ADD deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL',
        );
        // The values of DraftDecision; only a draft is asked, and the time comes with the answer (Round::decide).
        $this->addSql("ALTER TABLE rvw_round ADD CONSTRAINT rvw_round_decision_check CHECK (decision IS NULL OR (decision IN ('shared', 'kept_private') AND kind = 'draft'))");
        $this->addSql('ALTER TABLE rvw_round ADD CONSTRAINT rvw_round_decided_check CHECK ((decision IS NULL) = (decided_at IS NULL))');
        // Only an unshared draft is deleted, and deleting closes it (Round::delete).
        $this->addSql("ALTER TABLE rvw_round ADD CONSTRAINT rvw_round_deleted_check CHECK (deleted_at IS NULL OR (kind = 'draft' AND shared_at IS NULL AND state = 'closed'))");

        $this->addSql('DROP INDEX rvw_round_ticket_id_owner_id_open_draft_key');
        $this->addSql(
            'CREATE UNIQUE INDEX rvw_round_ticket_id_owner_id_open_draft_key ON rvw_round (organization_id, ticket_id, owner_id)'
            ." WHERE kind = 'draft' AND shared_at IS NULL AND state = 'open'",
        );

        // DbalRounds::save removes the screenshots and pins a draft no longer has; a round itself is never deleted.
        $this->addSql('GRANT DELETE ON rvw_screenshot TO pinround_app');
        $this->addSql('GRANT DELETE ON rvw_pin TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON rvw_pin FROM pinround_app');
        $this->addSql('REVOKE DELETE ON rvw_screenshot FROM pinround_app');

        // Fails while an owner has a deleted draft and an open one on one ticket, as the old slot holds both.
        $this->addSql('DROP INDEX rvw_round_ticket_id_owner_id_open_draft_key');
        $this->addSql(
            'CREATE UNIQUE INDEX rvw_round_ticket_id_owner_id_open_draft_key ON rvw_round (organization_id, ticket_id, owner_id)'
            ." WHERE kind = 'draft' AND shared_at IS NULL",
        );

        $this->addSql('ALTER TABLE rvw_round DROP CONSTRAINT rvw_round_deleted_check');
        $this->addSql('ALTER TABLE rvw_round DROP CONSTRAINT rvw_round_decided_check');
        $this->addSql('ALTER TABLE rvw_round DROP CONSTRAINT rvw_round_decision_check');
        $this->addSql('ALTER TABLE rvw_round DROP decided_at, DROP decision, DROP deleted_at');
    }
}
