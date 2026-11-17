<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ast_suggestion, the AI suggestions kept for the person who asked (P08.T7, "Check with AI"), with row-level security.
 * It holds the checked answer and its label (model, prompt version), never the draft or the prompt. No entity maps it,
 * so the schema_filter hides it.
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ast_suggestion, the AI suggestions kept for their requester, with row-level security';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ast_suggestion (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL,'
            .' purpose TEXT NOT NULL, result JSONB NOT NULL, model TEXT NOT NULL, prompt_version TEXT NOT NULL,'
            .' created_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // 'pins' is for AI pin suggestions (data-model.md); nothing writes it yet.
            ." CONSTRAINT ast_suggestion_purpose_check CHECK (purpose IN ('intake', 'pins')),"
            .' CONSTRAINT ast_suggestion_result_check CHECK (jsonb_typeof(result) = \'object\'),'
            // The model name comes from the provider's answer; the cap keeps an odd one from bloating rows, as on ast_usage.
            .' CONSTRAINT ast_suggestion_model_check CHECK (char_length(model) BETWEEN 1 AND 200),'
            .' CONSTRAINT ast_suggestion_prompt_version_check CHECK (char_length(prompt_version) BETWEEN 1 AND 200))',
        );
        foreach (TenantTable::enableRls('ast_suggestion') as $statement) {
            $this->addSql($statement);
        }
        // A suggestion is written once and then only read; the 7-day purge (not built yet) gets its rights with the retention job.
        $this->addSql('GRANT SELECT, INSERT ON ast_suggestion TO pinround_app');
        $this->addSql('GRANT SELECT ON ast_suggestion TO pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ast_suggestion');
    }
}
