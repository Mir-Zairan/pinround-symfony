<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

final class MessageForAnotherTenantRefused extends \LogicException
{
    public function __construct()
    {
        parent::__construct('A message for another organization cannot be dispatched or handled while this organization is set.');
    }
}
