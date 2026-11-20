<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_pin_fixed_on_try_check of Version20261121120000 let a pin with no verdict name a try: verdict = 'fixed' is null
 * then, and a CHECK passes on null. IS NOT DISTINCT FROM is false for a null verdict, so the check refuses that row too.
 * A new migration, not an edit of that one, as databases may have run it already.
 */
final class Version20261121170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refuse a fixed-on try on a pin with no verdict';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_pin DROP CONSTRAINT rvw_pin_fixed_on_try_check');
        $this->addSql(
            'ALTER TABLE rvw_pin ADD CONSTRAINT rvw_pin_fixed_on_try_check'
            ." CHECK (fixed_on_try IS NULL OR (verdict IS NOT DISTINCT FROM 'fixed' AND fixed_on_try BETWEEN 2 AND 5))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_pin DROP CONSTRAINT rvw_pin_fixed_on_try_check');
        $this->addSql(
            'ALTER TABLE rvw_pin ADD CONSTRAINT rvw_pin_fixed_on_try_check'
            ." CHECK (fixed_on_try IS NULL OR (verdict = 'fixed' AND fixed_on_try BETWEEN 2 AND 5))",
        );
    }
}
