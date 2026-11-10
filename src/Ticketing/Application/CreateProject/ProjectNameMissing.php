<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateProject;

/**
 * Nothing is left of the name after trimming. In the Application, not the Domain, so the project form may catch it
 * and show its message.
 */
final class ProjectNameMissing extends \InvalidArgumentException
{
}
