<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two-step login (TOTP, ADR-0012), one row per user. Global like idn_user, so no organization_id and no row-level
 * security, and pinround_readonly gets nothing. The seed is stored only encrypted (FieldEncryption: key id, nonce,
 * secretbox), the recovery codes only as Argon2id hashes. Turning it off (a later step of P01.T6) is not built yet, so
 * the app gets no DELETE.
 */
final class Version20261108180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Identity table idn_mfa_secret';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE idn_mfa_secret (user_id UUID NOT NULL, secret_enc BYTEA NOT NULL,'
            .' confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, recovery_code_hashes TEXT[] NOT NULL,'
            .' last_used_step BIGINT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id),'
            .' CONSTRAINT idn_mfa_secret_user_id_fkey FOREIGN KEY (user_id) REFERENCES idn_user (id))',
        );
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON idn_mfa_secret TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idn_mfa_secret');
    }
}
