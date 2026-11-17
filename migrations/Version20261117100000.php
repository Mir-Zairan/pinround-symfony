<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\MonthlyPartitions;
use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ast_usage, one row per server-side AI call (ai-architecture.md › Cost, rate limits and metering), partitioned by UTC
 * month of at, with row-level security on the parent and every partition. It holds who, which feature, which provider and
 * model, token counts, duration and cost, never prompt or answer text. No entity maps it, so the schema_filter hides it
 * and its partitions.
 */
final class Version20261117100000 extends AbstractMigration
{
    private const string PARENT = 'ast_usage';
    // Fixed, like ntf_notification's, so every database migrated before 2027-11 has the same months.
    private const string FIRST_MONTH = '2026-11-01';
    private const string LAST_MONTH = '2027-12-01';
    // A database migrated later still gets its current month and the next two.
    private const int MONTHS_AHEAD = 2;

    public function getDescription(): string
    {
        return 'Create ast_usage, the AI call metering partitioned by month, with row-level security';
    }

    public function up(Schema $schema): void
    {
        // Token counts and cost stay NULL when unknown, never 0, so a sum never passes off a gap as free.
        $this->addSql(
            'CREATE TABLE ast_usage (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL,'
            .' feature TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NOT NULL,'
            .' input_tokens INT DEFAULT NULL, output_tokens INT DEFAULT NULL, duration_ms INT NOT NULL,'
            .' cost_estimate NUMERIC(12, 6) DEFAULT NULL, at TIMESTAMP WITH TIME ZONE NOT NULL,'
            // A partitioned table's key must contain the partition column.
            .' PRIMARY KEY (organization_id, id, at),'
            // AiFeature's values.
            ." CONSTRAINT ast_usage_feature_check CHECK (feature IN ('summary', 'explain', 'intake')),"
            // The provider and model names come from the provider's answer; the caps keep an odd one from bloating rows.
            .' CONSTRAINT ast_usage_provider_check CHECK (char_length(provider) BETWEEN 1 AND 64),'
            .' CONSTRAINT ast_usage_model_check CHECK (char_length(model) BETWEEN 1 AND 200),'
            .' CONSTRAINT ast_usage_input_tokens_check CHECK (input_tokens >= 0),'
            .' CONSTRAINT ast_usage_output_tokens_check CHECK (output_tokens >= 0),'
            .' CONSTRAINT ast_usage_duration_ms_check CHECK (duration_ms >= 0),'
            .' CONSTRAINT ast_usage_cost_estimate_check CHECK (cost_estimate >= 0))'
            .' PARTITION BY RANGE (at)',
        );
        // The monthly budget sums an organization's calls by time.
        $this->addSql('CREATE INDEX ast_usage_at_idx ON ast_usage (organization_id, at)');
        foreach (TenantTable::enableRls(self::PARENT) as $statement) {
            $this->addSql($statement);
        }
        // Append-only for the app: a metered call stays metered. Support reads it; the rows hold no content.
        $this->addSql('GRANT SELECT, INSERT ON ast_usage TO pinround_app');
        $this->addSql('GRANT SELECT ON ast_usage TO pinround_readonly');

        // No DEFAULT partition, so a row past the last month fails instead of piling up where retention cannot drop it.
        $firstMonth = new \DateTimeImmutable(self::FIRST_MONTH, new \DateTimeZone('UTC'));
        $lastMonth = max(
            new \DateTimeImmutable(self::LAST_MONTH, new \DateTimeZone('UTC')),
            MonthlyPartitions::monthOf(new \DateTimeImmutable('now'))->modify(\sprintf('+%d months', self::MONTHS_AHEAD)),
        );
        for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('first day of next month')) {
            foreach (MonthlyPartitions::statementsFor(self::PARENT, $month) as $statement) {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ast_usage');
    }
}
