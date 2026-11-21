<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * TicketCommands::create or report refused the assignee: they are not a lead or member of the project's team in the
 * organization, or the reporter who named them is in no role of that team. Nothing was saved. The message is
 * Ticketing's own; another module shows its own words for it.
 *
 * An interface, so the one exception Ticketing throws is caught by its own pages as it is and by other modules as this
 * Api type.
 */
interface AssigneeRefused extends \Throwable
{
}
