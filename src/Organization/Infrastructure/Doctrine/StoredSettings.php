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
    public function __construct(public StepPolicy $stepPolicy, public ?UserId $triagerId)
    {
    }
}
