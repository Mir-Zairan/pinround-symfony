<?php

declare(strict_types=1);

namespace App\Web\UI\MyReports;

use App\Insight\Api\MyReport;
use App\Insight\Api\ReportGroup;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\PinProgress;
use App\Workflow\Api\NeedsYouKind;

/**
 * The words of a report card (my-reports.md, Card). Pure.
 */
final class ReportCardText
{
    /** A report changed at most this long ago reads "changed recently". */
    public const int RECENT_SECONDS = 2 * 86400;

    /**
     * "no change for N days" for a Stuck report, "changed recently" within RECENT_SECONDS, else "updated 5 Nov", with
     * the year when it is not $now's.
     */
    public static function sideTextOf(MyReport $report, ReportGroup $group, \DateTimeImmutable $now): string
    {
        $secondsSinceChange = $now->getTimestamp() - $report->updatedAt->getTimestamp();
        if (ReportGroup::Stuck === $group) {
            return \sprintf('no change for %d days', intdiv($secondsSinceChange, 86400));
        }
        if ($secondsSinceChange <= self::RECENT_SECONDS) {
            return 'changed recently';
        }
        $updatedAt = $report->updatedAt->setTimezone($now->getTimezone());

        return 'updated '.$updatedAt->format($updatedAt->format('Y') === $now->format('Y') ? 'j M' : 'j M Y');
    }

    /** A quoted question longer than this many characters is cut, with "…". */
    public const int QUESTION_MAX_CHARACTERS = 80;

    /**
     * The Now line: "Done", the question that waits on the viewer ("Lena asks: “…”", or a plain line when it is not
     * known), "Waiting on Customer" for a label's turn, or the turn person's task with their first name ("Omar builds
     * it", "You test it").
     *
     * @param string|null $turnFirstName the first name of the report's turn person; null when they have none here
     * @param string|null $questionLine  questionLineOf the open question to the viewer; null when it is not known
     *
     * @throws \ValueError for a turn kind that is not a NeedsYouKind value
     */
    public static function nowTextOf(MyReport $report, ReportGroup $group, UserId $viewerId, ?string $turnFirstName, ?string $questionLine = null): string
    {
        if (ReportGroup::Done === $group) {
            return 'Done';
        }
        if (ReportGroup::NeedsYou === $group) {
            return $questionLine ?? 'A question waits for your answer';
        }
        if (null !== $report->turnLabel) {
            return 'Waiting on '.$report->turnLabel;
        }
        if (null === $report->turnUserId || null === $report->turnKind) {
            return 'No one needs to act now';
        }

        $isViewer = $report->turnUserId->equals($viewerId);
        [$verb, $rest] = match (NeedsYouKind::from($report->turnKind)) {
            NeedsYouKind::Answer => ['answer', ' a question'],
            NeedsYouKind::Check => ['check', ' the new ticket'],
            NeedsYouKind::Review => ['review', ' the code'],
            NeedsYouKind::Test => ['test', ' it'],
            NeedsYouKind::Assign => ['pick', ' a developer'],
            NeedsYouKind::Input => ['give', ' input'],
            NeedsYouKind::Decide => ['decide', ': go on or stop'],
            NeedsYouKind::Build => ['build', ' it'],
        };

        return $isViewer ? 'You '.$verb.$rest : ($turnFirstName ?? 'Someone').' '.$verb.'s'.$rest;
    }

    /**
     * "Lena asks: “Which order was it?”", on one line: line breaks, tabs, other control characters and runs of spaces
     * become one space, and a question over QUESTION_MAX_CHARACTERS is cut with "…".
     *
     * @param string|null $askerFirstName null when the asker has no name here, which reads "Someone asks"
     *
     * @return string|null null when the question has no visible character
     */
    public static function questionLineOf(?string $askerFirstName, string $question): ?string
    {
        $oneLine = trim((string) preg_replace('/[\s\p{Z}\p{Cc}\x{FEFF}]+/u', ' ', $question));
        if ('' === $oneLine) {
            return null;
        }
        if (mb_strlen($oneLine) > self::QUESTION_MAX_CHARACTERS) {
            $oneLine = rtrim(mb_substr($oneLine, 0, self::QUESTION_MAX_CHARACTERS)).'…';
        }

        return \sprintf('%s asks: “%s”', $askerFirstName ?? 'Someone', $oneLine);
    }

    /**
     * "1 of 2 pins OK", or "0 of 1 pin OK" for a checklist of one pin.
     */
    public static function pinProgressTextOf(PinProgress $progress): string
    {
        return \sprintf('%d of %d %s OK', $progress->okCount, $progress->pinCount, 1 === $progress->pinCount ? 'pin' : 'pins');
    }
}
