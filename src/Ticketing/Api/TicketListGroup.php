<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * One group of the tickets list (ADR-0014) with its steps, its header text as Workflow's StepQueries::listedSteps()
 * gives it.
 */
final readonly class TicketListGroup
{
    /** Workflow's group code of Done, which the list shows only when asked. */
    public const string DONE = 'done';

    /**
     * @param string               $code  one of todo, doing, check, wait, done
     * @param string               $label the group's name as people read it, for example "Checking"
     * @param string|null          $hint  the one-line hint under the group header; null for Done
     * @param list<TicketListStep> $steps in the order the team page shows them
     */
    public function __construct(public string $code, public string $label, public ?string $hint, public array $steps)
    {
    }
}
