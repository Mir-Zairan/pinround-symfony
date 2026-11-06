<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

final class TenantNotSet extends \LogicException
{
    public function __construct()
    {
        parent::__construct('No organization is set for this unit of work.');
    }
}
