<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalSettingsQueries implements SettingsQueries, StoredSettingsQueries
{
    public function __construct(private Connection $connection)
    {
    }

    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
    {
        // false: no row; null: no step_policy key (or a JSON null).
        return self::stepPolicyFrom($this->connection->fetchOne(
            "SELECT data->>'step_policy' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        ));
    }

    public function triagerOf(OrganizationId $organizationId): ?UserId
    {
        // false: no row; null: no triager_id key (or a JSON null).
        return self::triagerFrom($this->connection->fetchOne(
            "SELECT data->>'triager_id' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        ));
    }

    public function settingsOf(OrganizationId $organizationId): StoredSettings
    {
        $row = $this->connection->fetchNumeric(
            "SELECT data->>'step_policy', data->>'triager_id', data->>'ping_limit_hours' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        );

        return false === $row
            ? new StoredSettings(StepPolicy::Extend, null, DbalPingSettingsQueries::pingLimitHoursFrom(false))
            : new StoredSettings(self::stepPolicyFrom($row[0]), self::triagerFrom($row[1]), DbalPingSettingsQueries::pingLimitHoursFrom($row[2]));
    }

    private static function stepPolicyFrom(mixed $stepPolicy): StepPolicy
    {
        if (false === $stepPolicy || null === $stepPolicy) {
            return StepPolicy::Extend;
        }

        return (\is_string($stepPolicy) ? StepPolicy::tryFrom($stepPolicy) : null) ?? throw new \LogicException('org_settings holds an unknown step policy.');
    }

    private static function triagerFrom(mixed $triagerId): ?UserId
    {
        return \is_string($triagerId) ? new UserId($triagerId) : null;
    }
}
