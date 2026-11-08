<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum ConfirmItsYouOutcome
{
    case Confirmed;
    /** The password or the code was wrong; one outcome for both, so the page never tells which. */
    case WrongPasswordOrCode;
    /** There is no code to ask for: it was never turned on, or another request turned it off first. */
    case TwoStepLoginOff;
}
