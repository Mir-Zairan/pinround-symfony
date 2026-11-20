<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The person a page of people starts after is not a member of the organization. In the Application, not the Domain, so
 * the People page may catch it.
 */
final class NotInTheOrganization extends \DomainException
{
}
