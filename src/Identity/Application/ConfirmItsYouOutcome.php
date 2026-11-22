<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum ConfirmItsYouOutcome
{
    case Confirmed;
    /** The password or the code was wrong; one outcome for both, so the page never tells which. */
    case WrongPasswordOrCode;
    /** Only the password was asked for, as the demo switch skips two-step login (ADR-0049), and it was wrong. */
    case WrongPassword;
    /** There is no code to ask for: it was never turned on, or another request turned it off first. */
    case TwoStepLoginOff;
}
