<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

/**
 * The cap a person reached when LocalPackages::issue stored no package.
 */
enum LocalPackageLimit
{
    /** They hold as many unused, unexpired packages as the cap allows. */
    case OpenPackages;

    /** They were given as many packages in the last hour as the cap allows, used, expired and open alike. */
    case PackagesPerHour;
}
