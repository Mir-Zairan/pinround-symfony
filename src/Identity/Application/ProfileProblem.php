<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Why UpdateProfile saved nothing: one case per form field that holds a value the user cannot keep.
 */
enum ProfileProblem
{
    case NameBlank;
    case TimeZoneUnknown;
    case ThemeUnknown;
}
