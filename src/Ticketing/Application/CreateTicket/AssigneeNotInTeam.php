<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

/**
 * The assignee is not a lead or member of the project's team in the ticket's organization, so the ticket would sit in
 * To do with someone who cannot move it, or name a person of another organization.
 */
final class AssigneeNotInTeam extends \DomainException
{
}
