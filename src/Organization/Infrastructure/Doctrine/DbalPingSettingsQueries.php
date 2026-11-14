<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

final readonly class DbalPingSettingsQueries implements PingSettingsQueries
{
    private const int DEFAULT_PING_LIMIT_HOURS = 4;

    private const array ALLOWED_PING_LIMIT_HOURS = ['1' => 1, '4' => 4, '8' => 8, '24' => 24];

    public function __construct(private Connection $connection)
    {
    }

    public function pingLimitHoursOf(OrganizationId $organizationId): int
    {
        $pingLimitHours = $this->connection->fetchOne(
            "SELECT data->>'ping_limit_hours' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        );

        // false: no row; null: no ping_limit_hours key (or a JSON null).
        if (false === $pingLimitHours || null === $pingLimitHours) {
            return self::DEFAULT_PING_LIMIT_HOURS;
        }

        if (!\is_string($pingLimitHours) || !\array_key_exists($pingLimitHours, self::ALLOWED_PING_LIMIT_HOURS)) {
            throw new \LogicException('org_settings holds an unknown ping limit.');
        }

        return self::ALLOWED_PING_LIMIT_HOURS[$pingLimitHours];
    }
}
