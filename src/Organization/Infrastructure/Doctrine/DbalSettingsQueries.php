<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalSettingsQueries implements SettingsQueries
{
    public function __construct(private Connection $connection)
    {
    }

    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
    {
        // false: no row; null: no step_policy key (or a JSON null).
        $stepPolicy = $this->connection->fetchOne(
            "SELECT data->>'step_policy' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        );
        if (false === $stepPolicy || null === $stepPolicy) {
            return StepPolicy::Extend;
        }

        return (\is_string($stepPolicy) ? StepPolicy::tryFrom($stepPolicy) : null) ?? throw new \LogicException('org_settings holds an unknown step policy.');
    }

    public function triagerOf(OrganizationId $organizationId): ?UserId
    {
        // false: no row; null: no triager_id key (or a JSON null).
        $triagerId = $this->connection->fetchOne(
            "SELECT data->>'triager_id' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        );

        return \is_string($triagerId) ? new UserId($triagerId) : null;
    }
}
