<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The colour theme a user picked; System follows the device's setting. Stored as text with a CHECK constraint in
 * idn_user.theme; a new case needs the constraint widened too.
 */
enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
