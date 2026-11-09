<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turning two-step login off (P01.T6, ADR-0012) deletes the user's idn_mfa_secret row, so a new setup starts from a new
 * seed. No schema change; pinround_readonly still gets nothing.
 */
final class Version20261108200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let the app delete idn_mfa_secret rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT DELETE ON idn_mfa_secret TO pinround_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE DELETE ON idn_mfa_secret FROM pinround_app');
    }
}
