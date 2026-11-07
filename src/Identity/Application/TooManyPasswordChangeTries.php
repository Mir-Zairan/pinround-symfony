<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class TooManyPasswordChangeTries extends \RuntimeException
{
    /**
     * @param int $minutesToWait at least 1, at most the interval of the limiter
     */
    public function __construct(public readonly int $minutesToWait)
    {
        parent::__construct('Too many tries to change the password.');
    }
}
