<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The steps of one group in the "Move to another step…" list.
 */
final readonly class MoveChoiceGroup
{
    /**
     * @param string           $groupCode  one of todo, doing, check, wait, done
     * @param string           $groupLabel the group's name as people read it, for example "Checking"
     * @param list<MoveChoice> $choices    at least one, in the order the steps were given
     */
    public function __construct(public string $groupCode, public string $groupLabel, public array $choices)
    {
    }
}
