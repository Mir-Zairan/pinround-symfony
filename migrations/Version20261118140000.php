<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Use as main summary" for a laptop result (P08.T4 step 4, P08.T9 step 4). tkt_summary takes the source 'laptop' and
 * chosen_by_id, the person who made it the main summary; a laptop summary names its poster, model, prompt version and
 * chooser. ast_local_package keeps the result's label as it was posted (result_label) and when it was made the main
 * summary (made_main_at), and an index finds a ticket's newest proposal: a posted result not yet made the main summary.
 * Results posted before this migration get their label with "someone" for the poster, whose first name is not stored.
 * The grants of Version20261111120000 and Version20261118120000 already cover the new columns.
 */
final class Version20261118140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add laptop summaries to tkt_summary and the label and main-summary time of a laptop result';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tkt_summary DROP CONSTRAINT tkt_summary_source_check');
        $this->addSql("ALTER TABLE tkt_summary ADD CONSTRAINT tkt_summary_source_check CHECK (source IN ('sync', 'ai', 'explanation', 'human', 'laptop'))");
        $this->addSql('ALTER TABLE tkt_summary ADD COLUMN chosen_by_id UUID DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE tkt_summary ADD CONSTRAINT tkt_summary_laptop_label_check'
            ." CHECK (source <> 'laptop' OR (author_id IS NOT NULL AND model IS NOT NULL AND prompt_version IS NOT NULL AND chosen_by_id IS NOT NULL))",
        );

        $this->addSql('ALTER TABLE ast_local_package ADD COLUMN result_label TEXT DEFAULT NULL, ADD COLUMN made_main_at TIMESTAMP WITH TIME ZONE DEFAULT NULL');
        // LocalResult::LABEL_FORMAT. FORCE ROW LEVEL SECURITY hides every row from the owner with no tenant set, so it
        // is lifted for the update only, as in Version20261110180000; the migration's transaction restores it on failure.
        $this->addSql('ALTER TABLE ast_local_package NO FORCE ROW LEVEL SECURITY');
        $this->addSql("UPDATE ast_local_package SET result_label = 'posted by someone using local model ' || model || ' (unverified)' WHERE used_at IS NOT NULL");
        $this->addSql('ALTER TABLE ast_local_package FORCE ROW LEVEL SECURITY');
        $this->addSql(
            'ALTER TABLE ast_local_package ADD CONSTRAINT ast_local_package_result_label_check'
            .' CHECK ((used_at IS NULL) = (result_label IS NULL) AND char_length(result_label) BETWEEN 1 AND 400)',
        );
        $this->addSql('ALTER TABLE ast_local_package ADD CONSTRAINT ast_local_package_made_main_at_check CHECK (made_main_at IS NULL OR used_at IS NOT NULL)');
        // The ticket page's proposal: the ticket's newest posted result not yet made the main summary.
        $this->addSql(
            'CREATE INDEX ast_local_package_ticket_id_used_at_idx ON ast_local_package (organization_id, ticket_id, used_at DESC)'
            .' WHERE used_at IS NOT NULL AND made_main_at IS NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ast_local_package_ticket_id_used_at_idx');
        $this->addSql('ALTER TABLE ast_local_package DROP COLUMN made_main_at, DROP COLUMN result_label');
        $this->addSql('ALTER TABLE tkt_summary DROP CONSTRAINT tkt_summary_laptop_label_check');
        $this->addSql('ALTER TABLE tkt_summary DROP COLUMN chosen_by_id');
        $this->addSql('ALTER TABLE tkt_summary DROP CONSTRAINT tkt_summary_source_check');
        $this->addSql("ALTER TABLE tkt_summary ADD CONSTRAINT tkt_summary_source_check CHECK (source IN ('sync', 'ai', 'explanation', 'human'))");
    }
}
