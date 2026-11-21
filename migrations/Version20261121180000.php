<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * rvw_screenshot_retake_of_idx serves rvw_screenshot_retake_of_fkey of Version20261121100000 when a first try is removed,
 * and the latest-try filter of DbalRoundQueries.
 *
 * rvw_screenshot holds every round's pictures and is written on every draft change, so the index is built CONCURRENTLY
 * (data-model.md, migration rules), which cannot run in a transaction: this migration runs without one, and holds
 * nothing else. A failed concurrent build leaves an INVALID index of that name behind and no version row, so up() drops
 * a leftover first and a re-run builds it again; a database that built the index inside Version20261121100000 before
 * that migration was changed gets it built again the same way. Indexes need no grants, and row-level security is
 * table-wide.
 */
final class Version20261121180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the index of a retake\'s first try to rvw_screenshot';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS rvw_screenshot_retake_of_idx');
        $this->addSql('CREATE INDEX CONCURRENTLY rvw_screenshot_retake_of_idx ON rvw_screenshot (organization_id, round_id, retake_of)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY rvw_screenshot_retake_of_idx');
    }
}
