<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

final class TenantSwitchRefused extends \LogicException
{
    public function __construct()
    {
        parent::__construct('The organization cannot change while a database transaction is open or entities are loaded. End the transaction, flush, and clear the entity manager first.');
    }
}
