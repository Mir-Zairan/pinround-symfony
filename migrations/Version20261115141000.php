<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The target of an upload intent, target_kind and target_id of med_upload_intent: what it was begun for, so completing
 * it checks that target again (UploadIntent). The lengths repeat UploadIntent::MAX_TARGET_KIND_LENGTH and
 * MAX_TARGET_ID_LENGTH, counted in characters as mb_strlen counts them. The columns have no default, so the migration
 * fails on a table that holds intents already rather than inventing a target for them; no route begins an upload yet.
 * The table's grants and row-level security are table-wide, so the new columns need neither, and no index uses them.
 */
final class Version20261115141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the upload target columns target_kind and target_id to med_upload_intent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE med_upload_intent ADD COLUMN target_kind TEXT NOT NULL, ADD COLUMN target_id TEXT NOT NULL,'
            .' ADD CONSTRAINT med_upload_intent_target_kind_check CHECK (char_length(target_kind) BETWEEN 1 AND 40),'
            .' ADD CONSTRAINT med_upload_intent_target_id_check CHECK (char_length(target_id) BETWEEN 1 AND 100)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE med_upload_intent DROP CONSTRAINT med_upload_intent_target_id_check,'
            .' DROP CONSTRAINT med_upload_intent_target_kind_check, DROP COLUMN target_id, DROP COLUMN target_kind',
        );
    }
}
