<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assist\Laptop;

use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Committed AI settings for an organization LogsInUsers created, whose tearDown deletes them with the organization.
 * Next to LogsInUsers, through whose committedAs it writes.
 */
trait StoresAiSettings
{
    /**
     * @param array<string, bool|string> $ai the org_settings.data.ai keys (AiSettingsQueries)
     */
    private function storeAiSettings(OrganizationId $organizationId, array $ai): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('ai', ?::jsonb), TIMESTAMPTZ '2026-11-01 08:00:00+00')
             ON CONFLICT (organization_id) DO UPDATE SET data = org_settings.data || EXCLUDED.data",
            [$organizationId->toString(), json_encode($ai, \JSON_THROW_ON_ERROR)],
        ));
    }

    /**
     * @return array<string, bool|string>
     */
    private static function laptopAi(string $where = 'laptop'): array
    {
        return ['on' => true, 'who' => 'everyone', 'where' => $where];
    }
}
