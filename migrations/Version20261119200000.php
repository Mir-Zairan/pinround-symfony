<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * api_idempotency_key, the answers of POST /api/v1 requests sent with an Idempotency-Key header (P09.T7), with
 * row-level security: a replay of the same key by the same token gets the first answer and changes nothing. A key is
 * reserved before the request runs (status NULL) and gets its answer in the same transaction, so a second request with
 * the key waits for the first and then replays it. The answer is kept as the exact JSON text sent, so a replay is the
 * same bytes (jsonb would reorder its keys). No entity maps it, so the schema_filter hides it.
 */
final class Version20261119200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_idempotency_key, the stored answers of idempotent /api/v1 requests, with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE api_idempotency_key (organization_id UUID NOT NULL, token_id UUID NOT NULL, key TEXT NOT NULL,'
            .' request_hash BYTEA NOT NULL, status INT DEFAULT NULL, response TEXT DEFAULT NULL,'
            .' created_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, token_id, key),'
            // IdempotencyKeys::KEY_PATTERN: 1 to 100 characters a header can carry plainly.
            ." CONSTRAINT api_idempotency_key_key_check CHECK (key ~ '^[A-Za-z0-9_.:-]{1,100}$'),"
            // A SHA-256.
            .' CONSTRAINT api_idempotency_key_request_hash_check CHECK (octet_length(request_hash) = 32),'
            .' CONSTRAINT api_idempotency_key_answer_check CHECK ((status IS NULL) = (response IS NULL)),'
            .' CONSTRAINT api_idempotency_key_status_check CHECK (status BETWEEN 200 AND 599),'
            // One response's JSON body; a tool's answer is far smaller, so this only stops a runaway row.
            .' CONSTRAINT api_idempotency_key_response_check CHECK (octet_length(response) <= 1048576))',
        );
        // The 24-hour purge (P09.T7) reads by age.
        $this->addSql('CREATE INDEX api_idempotency_key_created_at_idx ON api_idempotency_key (organization_id, created_at)');

        foreach (TenantTable::enableRls('api_idempotency_key') as $statement) {
            $this->addSql($statement);
        }
        // UPDATE gives a reserved key its answer; the purge that deletes comes with the retention job.
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON api_idempotency_key TO pinround_app');
        $this->addSql('GRANT SELECT ON api_idempotency_key TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_idempotency_key');
    }
}
