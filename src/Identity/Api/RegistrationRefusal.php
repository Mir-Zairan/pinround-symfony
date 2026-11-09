<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * Why InvitedPersonRegistration created no account: one case per input it cannot take.
 */
enum RegistrationRefusal
{
    case InvalidEmail;
    /** Someone already has an account with this email, in any letter case. */
    case EmailTaken;
    case NameBlank;
    case NameTooLong;
    case PasswordTooShort;
}
