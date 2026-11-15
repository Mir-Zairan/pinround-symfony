<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The question pause of tkt_ticket (P04.T2): who must answer the open question and who asked it. Both are null while
 * no question is open, and both are set while one is. The table's grants are table-wide, so the new columns need none.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the question pause columns asked_user_id and asker_id to tkt_ticket';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE tkt_ticket ADD COLUMN asked_user_id UUID NULL, ADD COLUMN asker_id UUID NULL,'
            .' ADD CONSTRAINT tkt_ticket_question_pause_check CHECK ((asked_user_id IS NULL) = (asker_id IS NULL))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE tkt_ticket DROP CONSTRAINT tkt_ticket_question_pause_check,'
            .' DROP COLUMN asker_id, DROP COLUMN asked_user_id',
        );
    }
}
