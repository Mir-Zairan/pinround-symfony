<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum TwoStepLoginOffOutcome
{
    case TurnedOff;
    /** The password or the code was wrong; one outcome for both, so the page never tells which. */
    case WrongPasswordOrCode;
    /** Nothing to turn off: it was never confirmed, or another request turned it off first. */
    case AlreadyOff;
}
