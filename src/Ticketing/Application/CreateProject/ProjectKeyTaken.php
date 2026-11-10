<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateProject;

/**
 * Another project of the organization already uses the key. In the Application, not the Domain, so the project form
 * may catch it and show its message.
 */
final class ProjectKeyTaken extends \DomainException
{
}
