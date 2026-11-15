<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes that let a begin's storage quota check (DoctrineStorageQuota) and a person's open upload count
 * (DoctrineUploadIntents::openCountOf) read only the index, not every row of the organization: bytes and max_bytes are
 * key columns rather than INCLUDE ones, which the Doctrine mapping cannot express. The open-intent index is partial,
 * so used intents, which are never deleted, do not grow it. A plain CREATE INDEX inside the migration's transaction:
 * both tables are new in this phase and hold no rows yet in any deployed database, and no migration runs without a
 * transaction so far. Indexes need no grants, and row-level security is table-wide.
 */
final class Version20261115160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index-only paths for the storage quota to med_object and med_upload_intent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX med_object_status_bytes_idx ON med_object (organization_id, status, bytes)');
        $this->addSql('CREATE INDEX med_upload_intent_open_idx ON med_upload_intent (organization_id, expires_at, user_id, max_bytes) WHERE used_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX med_upload_intent_open_idx');
        $this->addSql('DROP INDEX med_object_status_bytes_idx');
    }
}
