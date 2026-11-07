<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create processed_message, the record of which handler ran its effect for which message (OnceOnlyHandling)';
    }

    public function up(Schema $schema): void
    {
        // No organization_id and no row-level security: global plumbing like the Messenger tables. A message id is a
        // UUIDv7 unique across organizations, and a row holds no tenant data, only that id and a handler name.
        $this->addSql(
            'CREATE TABLE processed_message (message_id UUID NOT NULL, handler TEXT NOT NULL,'
            .' processed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL, PRIMARY KEY (message_id, handler))',
        );
        // For the retention job (P11) that deletes old rows in chunks.
        $this->addSql('CREATE INDEX processed_message_processed_at_idx ON processed_message (processed_at)');
        // Append-only for the app: a row that could be changed or deleted would let an effect run twice.
        // pinround_readonly gets nothing: the rows are not report data.
        $this->addSql('GRANT SELECT, INSERT ON processed_message TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processed_message');
    }
}
