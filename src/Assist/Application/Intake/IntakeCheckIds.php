<?php

declare(strict_types=1);

namespace App\Assist\Application\Intake;

use App\Assist\Api\IntakeCheckId;

interface IntakeCheckIds
{
    /**
     * A new check's id: a UUIDv7 of the current time, so ids sort by when their check was asked for.
     */
    public function next(): IntakeCheckId;
}
