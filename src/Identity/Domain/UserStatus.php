<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Stored as text with a CHECK constraint in idn_user.status; a new case needs the constraint widened too.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
