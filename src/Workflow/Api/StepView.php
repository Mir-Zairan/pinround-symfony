<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * A step as a team sees it in lists and on the Team page. Codes are strings because other modules may not use
 * Workflow's Domain enums.
 */
final readonly class StepView
{
    /**
     * @param string      $id         the step's uuid
     * @param string      $groupCode  one of todo, doing, check, wait, done
     * @param string      $groupLabel the group's name as people read it, for example "Checking"
     * @param int         $position   orders the step inside its group, lowest first
     * @param string      $origin     core, company or team
     * @param string|null $coreCode   backlog, todo, progress, review, test, input, hold or done for a core step; null
     *                                otherwise
     */
    public function __construct(
        private string $id,
        private string $name,
        private string $groupCode,
        private string $groupLabel,
        private int $position,
        private string $origin,
        private ?string $coreCode,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function groupCode(): string
    {
        return $this->groupCode;
    }

    public function groupLabel(): string
    {
        return $this->groupLabel;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function coreCode(): ?string
    {
        return $this->coreCode;
    }
}
