<?php

declare(strict_types=1);

namespace App\Insight\Application;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\MyReportsCursor;
use App\Insight\Api\MyReportsPage;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;
use App\Insight\Api\ViewersTurns;
use App\Insight\Api\WhoseTurnPage;
use App\Insight\Application\MyReports\MyReportsRows;
use App\Ticketing\Api\ListFilter;

final readonly class HomeQueriesService implements HomeQueries
{
    // At least Ticketing's WaitingOn::LABEL_MAX_LENGTH, out of Insight's reach: a smaller cap would answer 404 to the
    // Show more link, and the next page's cursor, of a label group with a longer label.
    private const int GROUP_KEY_MAX_LENGTH = 100;

    public function __construct(private WhoseTurnRows $whoseTurnRows, private MyReportsRows $myReportsRows)
    {
    }

    public function whoseTurn(TicketViewer $viewer, ?string $projectId, ?string $openedGroup, ?TurnGroupCursor $after, ?string $searchText = null): WhoseTurnPage
    {
        if (null !== $projectId && !ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('Whose turn is read in a project given by its canonical lowercase uuid.');
        }
        if (null !== $searchText && !ListFilter::isSearchText($searchText)) {
            throw new \InvalidArgumentException('Whose turn is searched with a trimmed text of 1 to 200 characters, valid UTF-8 without NUL.');
        }
        if (null !== $openedGroup && !self::isGroupKey($openedGroup)) {
            throw new \InvalidArgumentException('The opened whose-turn group is a user id or a label of at most 100 characters.');
        }
        if (null !== $after?->turnLabel && !self::isGroupKey($after->turnLabel)) {
            throw new \InvalidArgumentException('A whose-turn cursor\'s label has at most 100 characters.');
        }

        $read = $this->whoseTurnRows->groupedRowsOf($viewer, $projectId, $openedGroup, $after, self::GROUPS_PER_PAGE + 1, $searchText);
        $groups = $read['groups'];
        $viewerId = $viewer->viewerId;
        $isViewers = static fn (TurnGroup $group): bool => null !== $group->turnUserId && $group->turnUserId->equals($viewerId);
        // strcmp, not <=>: PHP compares numeric strings such as the labels "9" and "10" as numbers, the database byte by byte.
        usort($groups, static fn (TurnGroup $one, TurnGroup $other): int => [
            $isViewers($other), $one->oldestWaitingSince, null === $one->turnUserId, strcmp($one->groupKey(), $other->groupKey()),
        ] <=> [
            $isViewers($one), $other->oldestWaitingSince, null === $other->turnUserId, 0,
        ]);

        $viewersGroups = null === $after && [] !== $groups && $isViewers($groups[0]) ? [array_shift($groups)] : [];
        $nextCursor = null;
        if (\count($groups) > self::GROUPS_PER_PAGE) {
            $groups = \array_slice($groups, 0, self::GROUPS_PER_PAGE);
            $nextCursor = TurnGroupCursor::after($groups[self::GROUPS_PER_PAGE - 1]);
        }

        return new WhoseTurnPage([...$viewersGroups, ...$groups], $read['ticketCount'], $nextCursor, $read['isSearchCapped']);
    }

    public function viewersTurns(TicketViewer $viewer, int $limit): ViewersTurns
    {
        if ($limit < 1 || $limit > self::SHOWN_TICKETS_OF_THE_OPENED_GROUP) {
            throw new \InvalidArgumentException(\sprintf('The viewer\'s turns are read 1 to %d at a time.', self::SHOWN_TICKETS_OF_THE_OPENED_GROUP));
        }

        return $this->whoseTurnRows->viewersTurnsOf($viewer, $limit);
    }

    public function myReports(TicketViewer $viewer, ?MyReportsCursor $after): MyReportsPage
    {
        $reports = $this->myReportsRows->reportsOf($viewer, $after, self::MY_REPORTS_PER_PAGE + 1);
        if (\count($reports) <= self::MY_REPORTS_PER_PAGE) {
            return new MyReportsPage($reports, null);
        }
        $reports = \array_slice($reports, 0, self::MY_REPORTS_PER_PAGE);

        return new MyReportsPage($reports, MyReportsCursor::after($reports[self::MY_REPORTS_PER_PAGE - 1]));
    }

    private static function isGroupKey(string $groupKey): bool
    {
        return mb_check_encoding($groupKey, 'UTF-8') && mb_strlen($groupKey, 'UTF-8') <= self::GROUP_KEY_MAX_LENGTH;
    }
}
