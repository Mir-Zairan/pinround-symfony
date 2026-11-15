<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\StepPolicy;
use App\SharedKernel\Domain\UserId;

/**
 * The settings of one organization that the request-scoped reader keeps, as they were read in one query.
 */
final readonly class StoredSettings
{
    /**
     * @param 1|4|8|24 $pingLimitHours
     */
    public function __construct(
        public StepPolicy $stepPolicy,
        public ?UserId $triagerId,
        public int $pingLimitHours,
        public bool $isAutoClearOn = DbalPingSettingsQueries::DEFAULT_AUTO_CLEAR,
        public bool $isQuietHoursOn = DbalPingSettingsQueries::DEFAULT_QUIET_HOURS,
    ) {
    }
}
