<?php

declare(strict_types=1);

namespace App\Ticketing\Application\UnlinkProject;

/**
 * The project still has open tickets; they must be closed before it is unlinked from its team.
 */
final class ProjectHasOpenTickets extends \DomainException
{
    public function __construct(public readonly int $openTickets)
    {
        parent::__construct(1 === $openTickets
            ? '1 open ticket is still in this project. Close it first.'
            : \sprintf('%d open tickets are still in this project. Close them first.', $openTickets));
    }
}
