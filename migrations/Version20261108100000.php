<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The session stamp ends every session of a user when it changes (ADR-0012); the theme is the user's colour choice.
 * pinround_app's table grants on idn_user already cover new columns.
 */
final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add session_stamp and theme to idn_user';
    }

    public function up(Schema $schema): void
    {
        // A volatile default is evaluated per row, so every existing user gets its own stamp; the app sets it from then on.
        $this->addSql("ALTER TABLE idn_user ADD COLUMN session_stamp TEXT NOT NULL DEFAULT replace(gen_random_uuid()::text, '-', '')");
        $this->addSql('ALTER TABLE idn_user ALTER COLUMN session_stamp DROP DEFAULT');
        $this->addSql(
            "ALTER TABLE idn_user ADD COLUMN theme TEXT NOT NULL DEFAULT 'system',"
            ." ADD CONSTRAINT idn_user_theme_check CHECK (theme IN ('system', 'light', 'dark'))",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idn_user DROP COLUMN theme');
        $this->addSql('ALTER TABLE idn_user DROP COLUMN session_stamp');
    }
}
