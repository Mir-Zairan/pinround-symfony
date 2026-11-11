<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * The time zone the logged-in person saved on their profile, for other modules to print dates in.
 */
interface CurrentTimeZone
{
    /**
     * @return \DateTimeZone UTC when nobody is logged in or no zone is saved
     */
    public function zone(): \DateTimeZone;
}
