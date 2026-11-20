<?php

declare(strict_types=1);

namespace App\Insight\Application\MyReports;

use App\Insight\Api\MyReport;

/**
 * The groups of My reports, in the page's order (my-reports.md, Groups).
 */
enum ReportGroup: string
{
    /** An open question waits on the reporter's answer. */
    case NeedsYou = 'needs_you';
    /** Open, and no change for more than STUCK_AFTER_DAYS. */
    case Stuck = 'stuck';
    /** Open, and changed within STUCK_AFTER_DAYS. */
    case Moving = 'moving';
    case Done = 'done';

    public const int STUCK_AFTER_DAYS = 7;

    /**
     * @param \DateTimeImmutable $now the page's time; a report changed exactly STUCK_AFTER_DAYS before it still moves
     */
    public static function of(MyReport $report, \DateTimeImmutable $now): self
    {
        if ($report->isDone()) {
            return self::Done;
        }
        if ($report->isQuestionToReporter) {
            return self::NeedsYou;
        }

        return $report->updatedAt < $now->modify(\sprintf('-%d days', self::STUCK_AFTER_DAYS)) ? self::Stuck : self::Moving;
    }

    /**
     * @return int|null how many of the group's reports show before "Show N more"; null for all of them, 0 for none
     *                  (Done is folded)
     */
    public function shownCount(): ?int
    {
        return match ($this) {
            self::NeedsYou => null,
            self::Stuck, self::Moving => 4,
            self::Done => 0,
        };
    }
}
