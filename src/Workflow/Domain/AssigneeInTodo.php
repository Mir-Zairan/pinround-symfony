<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * Whether a verb needs the ticket to have an assignee while it is in To do: the "(no assignee)" and "(assigned)" of
 * the transition catalogue's From column. Other from steps take a ticket with or without one.
 */
enum AssigneeInTodo: string
{
    case Any = 'any';
    case Nobody = 'nobody';
    case Someone = 'someone';
}
