<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum TwoStepConfirmRefusal
{
    case WrongCode;
    /** Nothing to confirm: the user has not turned it on. */
    case NotStarted;
    case AlreadyOn;
}
