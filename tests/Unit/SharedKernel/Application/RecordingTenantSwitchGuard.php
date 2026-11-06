<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Application;

use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;

/**
 * Counts the switches TenantContext asks for, and refuses them when told to.
 */
final class RecordingTenantSwitchGuard implements TenantSwitchGuard
{
    public int $switchCount = 0;

    public bool $isRefusing = false;

    public function prepareSwitch(): void
    {
        ++$this->switchCount;

        if ($this->isRefusing) {
            throw new TenantSwitchRefused();
        }
    }
}
