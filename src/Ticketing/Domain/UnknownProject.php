<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * No project has this id in the organization of the current transaction; one of another organization counts as none.
 */
final class UnknownProject extends \DomainException
{
}
