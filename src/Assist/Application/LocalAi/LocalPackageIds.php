<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

use App\Assist\Api\LocalPackageId;

interface LocalPackageIds
{
    /**
     * A new package's id: a UUIDv7 of the current time.
     */
    public function next(): LocalPackageId;
}
