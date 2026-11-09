<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The invited email belongs to a member of the organization. In the Application, not the Domain, so the People page
 * may catch it.
 */
final class AlreadyInTheOrganization extends \DomainException
{
}
