<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * A step as the tickets list shows it: its group's header text with it, and whether it is removed. Codes are strings
 * because other modules may not use Workflow's Domain enums.
 */
final readonly class ListedStepView
{
    /**
     * @param string      $id         the step's uuid
     * @param string      $groupCode  one of todo, doing, check, wait, done
     * @param string      $groupLabel the group's name as people read it, for example "Checking"
     * @param string|null $groupHint  the one-line hint under the group header; null for Done
     * @param bool        $isArchived true for a removed step, which still holds the tickets left in it
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $groupCode,
        public string $groupLabel,
        public ?string $groupHint,
        public bool $isArchived,
    ) {
    }
}
