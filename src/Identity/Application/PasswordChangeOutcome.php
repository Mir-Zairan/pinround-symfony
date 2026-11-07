<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum PasswordChangeOutcome
{
    case PasswordChanged;
    case WrongCurrentPassword;
    case PasswordTooShort;
}
