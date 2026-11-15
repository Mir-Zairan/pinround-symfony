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

    public const bool DEFAULT_AUTO_CLEAR = true;

    public const bool DEFAULT_QUIET_HOURS = false;

    // How -> renders a JSON boolean; ->> would render the string "true" the same.
    private const array STORED_SWITCHES = ['true' => true, 'false' => false];

    public function __construct(private Connection $connection)
    {
    }

    public function pingLimitHoursOf(OrganizationId $organizationId): int
    {
        return self::pingLimitHoursFrom($this->connection->fetchOne(
            "SELECT data->>'ping_limit_hours' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        ));
    }

    public function isAutoClearOn(OrganizationId $organizationId): bool
    {
        return self::switchFrom('auto_clear', $this->connection->fetchOne(
            "SELECT data->'auto_clear' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        ), self::DEFAULT_AUTO_CLEAR);
    }

    public function isQuietHoursOn(OrganizationId $organizationId): bool
    {
        return self::switchFrom('quiet_hours', $this->connection->fetchOne(
            "SELECT data->'quiet_hours' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        ), self::DEFAULT_QUIET_HOURS);
    }

    /**
     * The ping limit from what a read of data->>'ping_limit_hours' gave, for every reader of org_settings.
     *
     * @param mixed $pingLimitHours false for no row; null for no ping_limit_hours key (or a JSON null)
     *
     * @return 1|4|8|24
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24
     */
    public static function pingLimitHoursFrom(mixed $pingLimitHours): int
    {
        if (false === $pingLimitHours || null === $pingLimitHours) {
            return self::DEFAULT_PING_LIMIT_HOURS;
        }

        if (!\is_string($pingLimitHours) || !\array_key_exists($pingLimitHours, self::ALLOWED_PING_LIMIT_HOURS)) {
            throw new \LogicException('org_settings holds an unknown ping limit.');
        }

        return self::ALLOWED_PING_LIMIT_HOURS[$pingLimitHours];
    }

    /**
     * An on/off setting from what a read of data->$key gave, for every reader of org_settings.
     *
     * @param mixed $storedSwitch false for no row; null for no such key; "null" for a JSON null
     *
     * @throws \LogicException when the stored switch is not a JSON boolean
     */
    public static function switchFrom(string $key, mixed $storedSwitch, bool $default): bool
    {
        if (false === $storedSwitch || null === $storedSwitch || 'null' === $storedSwitch) {
            return $default;
        }

        if (!\is_string($storedSwitch) || !\array_key_exists($storedSwitch, self::STORED_SWITCHES)) {
            throw new \LogicException(\sprintf('org_settings holds an unknown %s switch.', $key));
        }

        return self::STORED_SWITCHES[$storedSwitch];
    }
}
