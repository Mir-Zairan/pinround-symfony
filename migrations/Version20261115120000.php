<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * med_upload_intent and med_object, the upload slots and the uploaded pictures (data-model.md, Review and Media), as
 * tenant tables with row-level security. UploadIntent and MediaObject map them. The CHECKs repeat the domain's rules,
 * so a row written past the domain cannot hold a state the domain could not reach.
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create med_upload_intent and med_object with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE med_upload_intent (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL,'
            .' purpose TEXT NOT NULL, max_bytes BIGINT NOT NULL, mime_allowed TEXT[] NOT NULL, storage_key TEXT NOT NULL,'
            .' expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // The values of UploadPurpose.
            ." CONSTRAINT med_upload_intent_purpose_check CHECK (purpose IN ('screenshot')),"
            .' CONSTRAINT med_upload_intent_max_bytes_check CHECK (max_bytes > 0),'
            .' CONSTRAINT med_upload_intent_mime_allowed_check CHECK (cardinality(mime_allowed) > 0))',
        );
        $this->addSql('CREATE INDEX med_upload_intent_expires_at_idx ON med_upload_intent (organization_id, expires_at)');
        foreach (TenantTable::enableRls('med_upload_intent') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: nothing in the app removes an intent; a used one keeps its row with used_at.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON med_upload_intent TO pinround_app');
        $this->addSql('GRANT SELECT ON med_upload_intent TO pinround_readonly');

        $this->addSql(
            'CREATE TABLE med_object (organization_id UUID NOT NULL, id UUID NOT NULL, storage_key TEXT NOT NULL,'
            .' mime TEXT NOT NULL, bytes BIGINT NOT NULL, uploaded_by UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' status TEXT NOT NULL, reject_reason TEXT DEFAULT NULL, sha256 TEXT DEFAULT NULL, width INT DEFAULT NULL,'
            .' height INT DEFAULT NULL, variants JSONB DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // The values of MediaStatus.
            ." CONSTRAINT med_object_status_check CHECK (status IN ('pending', 'ready', 'rejected')),"
            ." CONSTRAINT med_object_rejected_check CHECK ((status = 'rejected') = (reject_reason IS NOT NULL)),"
            // MediaObject::MAX_REJECT_REASON_LENGTH, counted in characters as mb_strlen counts them.
            .' CONSTRAINT med_object_reject_reason_check CHECK (char_length(reject_reason) BETWEEN 1 AND 200),'
            // Each column on its own, so a pending or rejected row cannot hold half of a ready one either.
            ." CONSTRAINT med_object_ready_check CHECK ((status = 'ready') = (sha256 IS NOT NULL)"
            ." AND (status = 'ready') = (width IS NOT NULL) AND (status = 'ready') = (height IS NOT NULL)"
            ." AND (status = 'ready') = (variants IS NOT NULL)),"
            // In PostgreSQL's regex $ is the true end of the text, so a trailing newline is refused too.
            ." CONSTRAINT med_object_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$'),"
            .' CONSTRAINT med_object_bytes_check CHECK (bytes > 0),'
            .' CONSTRAINT med_object_dimensions_check CHECK (width > 0 AND height > 0),'
            ." CONSTRAINT med_object_variants_check CHECK (variants IS NULL OR jsonb_typeof(variants) = 'object'))",
        );
        $this->addSql('CREATE INDEX med_object_sha256_idx ON med_object (organization_id, sha256)');
        $this->addSql('CREATE INDEX med_object_status_created_at_idx ON med_object (organization_id, status, created_at)');
        foreach (TenantTable::enableRls('med_object') as $sql) {
            $this->addSql($sql);
        }
        // No DELETE: a rejected picture keeps its row with the reason.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON med_object TO pinround_app');
        $this->addSql('GRANT SELECT ON med_object TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE med_object');
        $this->addSql('DROP TABLE med_upload_intent');
    }
}
