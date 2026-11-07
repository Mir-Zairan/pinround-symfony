<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Password reset links (FR-ID-08). Global like idn_user, so no organization_id and no row-level security, and
 * pinround_readonly gets nothing. Only the SHA-256 of each link's token is kept. The app marks a reset used and never
 * removes one: the retention job (P11) purges old rows, so the app gets no DELETE.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Identity table idn_password_reset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_password_reset (id UUID NOT NULL, user_id UUID NOT NULL, token_hash BYTEA NOT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id),'
            .' CONSTRAINT idn_password_reset_user_id_fkey FOREIGN KEY (user_id) REFERENCES idn_user (id))',
        );
        $this->addSql('CREATE UNIQUE INDEX idn_password_reset_token_hash_key ON idn_password_reset (token_hash)');
        // The foreign key's index: removing a user, or purging a user's resets, finds them without a scan.
        $this->addSql('CREATE INDEX idn_password_reset_user_id_idx ON idn_password_reset (user_id)');
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON idn_password_reset TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idn_password_reset');
    }
}
