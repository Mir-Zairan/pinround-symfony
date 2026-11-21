<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validates the constraints Version20261121100000 and Version20261121170000 added NOT VALID, so PostgreSQL holds every
 * row to them, old ones too, and the planner may trust them. VALIDATE CONSTRAINT scans the table under SHARE UPDATE
 * EXCLUSIVE, which lets rounds be read and written meanwhile, unlike the ACCESS EXCLUSIVE lock of the migrations that
 * added them. On a database that ran those migrations before they added NOT VALID, the constraints are valid already
 * and this changes nothing.
 */
final class Version20261121180100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Validate the retake and fixed-on-try constraints of rvw_screenshot and rvw_pin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_screenshot VALIDATE CONSTRAINT rvw_screenshot_retake_of_check');
        $this->addSql('ALTER TABLE rvw_screenshot VALIDATE CONSTRAINT rvw_screenshot_retake_of_fkey');
        $this->addSql('ALTER TABLE rvw_pin VALIDATE CONSTRAINT rvw_pin_fixed_on_try_check');
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: a constraint cannot go back to NOT VALID, and the earlier migrations' down drop them.
    }
}
