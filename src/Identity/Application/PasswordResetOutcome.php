<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum PasswordResetOutcome
{
    case PasswordChanged;
    /** Nothing was used up: the link still works. */
    case PasswordTooShort;
    /** The same answer for an unknown, used and expired link, and for a link whose user can no longer log in. */
    case LinkNoLongerWorks;
}
