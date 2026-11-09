<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateProject;

/**
 * The key does not follow the format. In the Application, not the Domain, so the project form may catch it and show
 * its message.
 */
final class ProjectKeyNotValid extends \InvalidArgumentException
{
}
