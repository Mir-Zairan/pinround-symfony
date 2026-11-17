<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds tkt_summary.prompt_version (P08.T4), so every AI summary names the prompt template that made it, and makes an AI
 * summary carry its requester, model and prompt version (ADR-0020: every AI output is labeled). The grants of
 * Version20261111120000 already cover the new column.
 */
final class Version20261117160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add prompt_version to tkt_summary and require the label of an AI summary';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tkt_summary ADD COLUMN prompt_version TEXT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE tkt_summary ADD CONSTRAINT tkt_summary_ai_label_check'
            ." CHECK (source <> 'ai' OR (author_id IS NOT NULL AND model IS NOT NULL AND prompt_version IS NOT NULL))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tkt_summary DROP CONSTRAINT tkt_summary_ai_label_check');
        $this->addSql('ALTER TABLE tkt_summary DROP COLUMN prompt_version');
    }
}
