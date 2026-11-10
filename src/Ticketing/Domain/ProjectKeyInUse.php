<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * Another project of the organization has the key already, also one another transaction saved at the same time.
 */
final class ProjectKeyInUse extends \DomainException
{
}
