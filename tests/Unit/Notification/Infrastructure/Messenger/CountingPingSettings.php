<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;

/**
 * The quiet hours switch, counting its reads; a digest reads no other setting.
 */
final class CountingPingSettings implements PingSettingsQueries
{
    public int $readCount = 0;

    public function __construct(private readonly bool $isQuietHoursOn)
    {
    }

    public function pingLimitHoursOf(OrganizationId $organizationId): int
    {
        throw new \LogicException('A digest does not read the ping limit.');
    }

    public function isAutoClearOn(OrganizationId $organizationId): bool
    {
        throw new \LogicException('A digest does not read the auto-clear switch.');
    }

    public function isQuietHoursOn(OrganizationId $organizationId): bool
    {
        ++$this->readCount;

        return $this->isQuietHoursOn;
    }
}
