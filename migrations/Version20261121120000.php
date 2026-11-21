<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_pin.fixed_on_try (ADR-0048, P14.T6): the try of its chain that fixed a pin of an earlier try, as the chain counted
 * when its owner marked it, so a later retake leaves "Fixed on try N" as it was. From 2, as only a later try fixes a pin,
 * to Screenshot::MAX_TRIES, and only beside the fixed verdict. Row-level security and the table grants of
 * Version20261115180000 cover the new column as they are. No pin is filled in: under forced row-level security the
 * migrator sees no tenant's rows, and RoundCards shows a fixed pin without a try as plain "Fixed". The CHECK is added
 * NOT VALID, so rvw_pin is not scanned under this transaction's ACCESS EXCLUSIVE lock; Version20261121180100 validates
 * it.
 */
final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the try that fixed a pin of an earlier try';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_pin ADD fixed_on_try SMALLINT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE rvw_pin ADD CONSTRAINT rvw_pin_fixed_on_try_check'
            ." CHECK (fixed_on_try IS NULL OR (verdict = 'fixed' AND fixed_on_try BETWEEN 2 AND 5)) NOT VALID",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rvw_pin DROP COLUMN fixed_on_try');
    }
}
