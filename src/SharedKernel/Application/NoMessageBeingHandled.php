<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

final class NoMessageBeingHandled extends \LogicException
{
    public function __construct()
    {
        parent::__construct('No message with an id is being handled, so there is nothing to run once.');
    }
}
