<?php

declare(strict_types=1);

namespace App\Web\UI\MyReports;

/**
 * The four stages of a report's progress dots, from the five groups of its step, so they fit every team's own steps
 * (my-reports.md, Card).
 */
enum ReportStage: int
{
    case Reported = 1;
    case Building = 2;
    case Checking = 3;
    case Done = 4;

    /**
     * @param string $groupCode the step's group: todo, doing, check, wait or done; a waiting ticket sits at Building
     *
     * @throws \InvalidArgumentException for any other code
     */
    public static function ofStepGroup(string $groupCode): self
    {
        return match ($groupCode) {
            'todo' => self::Reported,
            'doing', 'wait' => self::Building,
            'check' => self::Checking,
            'done' => self::Done,
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not a step group.', $groupCode)),
        };
    }

    /**
     * @return list<string> every stage's label, in order
     */
    public static function labels(): array
    {
        return array_map(static fn (self $stage): string => $stage->label(), self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Reported',
            self::Building => 'Building',
            self::Checking => 'Checking',
            self::Done => 'Done',
        };
    }
}
