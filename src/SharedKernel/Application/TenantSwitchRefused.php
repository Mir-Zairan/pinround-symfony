<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

final class TenantSwitchRefused extends \LogicException
{
    public function __construct()
    {
        parent::__construct('The organization cannot change while changes of the current one are not saved. Flush or discard them first.');
    }
}
