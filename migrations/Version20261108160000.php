<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Web table web_home_focus (data-model.md, Web): each user's Home focus chip, per organization, so it follows the
 * user to every device. A tenant table with row-level security; no foreign key to idn_user, as global tables are reached
 * only through Identity. The app saves a choice by upsert and never removes one; pinround_readonly reads under the
 * tenant policy. No entity maps it, so the schema_filter hides it from the Doctrine diff.
 */
final class Version20261108160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Web table web_home_focus with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE web_home_focus (organization_id UUID NOT NULL, user_id UUID NOT NULL, focus TEXT NOT NULL,'
            .' updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (organization_id, user_id),'
            ." CONSTRAINT web_home_focus_focus_check CHECK (focus IN ('all', 'my_work', 'my_team', 'all_teams', 'admin')))",
        );
        foreach (TenantTable::enableRls('web_home_focus') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON web_home_focus TO pinround_app');
        $this->addSql('GRANT SELECT ON web_home_focus TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE web_home_focus');
    }
}
