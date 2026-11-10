<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The project already has an owning team; it is unlinked from it before it is linked to another (FR-ORG-06).
 */
final class ProjectAlreadyLinked extends \DomainException
{
}
