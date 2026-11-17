<?php

declare(strict_types=1);

namespace App\Review\Application\Checklist;

/** The one definition of the To do rule: a ticket in the step with this core code gets its checklist round (FR-RND-04). */
final class TodoStep
{
    public const string CORE_CODE = 'todo';
}
