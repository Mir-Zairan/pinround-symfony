<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Global tables (data-model.md, Identity): no organization_id and no row-level security, because one user belongs to
 * several organizations and login reads them before any organization is known. pinround_readonly gets nothing: with
 * no tenant policy it would read every organization's people. Nothing removes a row yet, so the app gets no DELETE.
 */
final class Version20261107190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Identity tables idn_user, idn_credential and idn_login_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_user (id UUID NOT NULL, email CITEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL,'
            .' locale TEXT NOT NULL, time_zone TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id),'
            ." CONSTRAINT idn_user_status_check CHECK (status IN ('active', 'blocked')))",
        );
        $this->addSql('CREATE UNIQUE INDEX idn_user_email_key ON idn_user (email)');
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON idn_user TO pinround_app');

        $this->addSql(
            'CREATE TABLE idn_credential (user_id UUID NOT NULL, password_hash TEXT NOT NULL,'
            .' changed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id),'
            .' CONSTRAINT idn_credential_user_id_fkey FOREIGN KEY (user_id) REFERENCES idn_user (id))',
        );
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON idn_credential TO pinround_app');

        // Unmapped, so doctrine.yaml's schema_filter hides it from the diff; append-only, the retention job (P11) purges it.
        $this->addSql(
            'CREATE TABLE idn_login_attempt (id UUID NOT NULL, email_hash BYTEA NOT NULL, ip_hash BYTEA NOT NULL,'
            .' success BOOLEAN NOT NULL, at TIMESTAMP WITH TIME ZONE NOT NULL, PRIMARY KEY (id))',
        );
        $this->addSql('CREATE INDEX idn_login_attempt_email_hash_at_idx ON idn_login_attempt (email_hash, at)');
        $this->addSql('GRANT SELECT, INSERT ON idn_login_attempt TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idn_login_attempt');
        $this->addSql('DROP TABLE idn_credential');
        $this->addSql('DROP TABLE idn_user');
    }
}
