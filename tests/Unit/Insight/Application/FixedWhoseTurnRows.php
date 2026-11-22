<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Api\TurnGroupCursor;
use App\Insight\Api\ViewersTurns;
use App\Insight\Application\WhoseTurnRows;

/**
 * Returns the groups it was given, in that order, as the database may return them in any order, and records each call.
 * Like the database, it leaves out the viewer's group after a cursor and keeps at most the limit of other groups; it
 * does not order them, so a test gives it the groups the database would pick.
 */
final class FixedWhoseTurnRows implements WhoseTurnRows
{
    /** @var list<array{?string, ?string, ?TurnGroupCursor, int, ?string}> the project id, opened group, cursor, limit and search text of each call */
    public array $calls = [];

    /** @var list<int> the limit of each viewersTurnsOf() call */
    public array $viewersTurnsLimits = [];

    /**
     * @param list<TurnGroup> $groups
     */
    public function __construct(private readonly array $groups, private readonly int $ticketCount = 0, private readonly bool $isSearchCapped = false)
    {
    }

    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup, ?TurnGroupCursor $after, int $otherGroupLimit, ?string $searchText = null): array
    {
        $this->calls[] = [$projectId, $openedGroup, $after, $otherGroupLimit, $searchText];
        $groups = [];
        $otherGroups = 0;
        foreach ($this->groups as $group) {
            $isViewers = null !== $group->turnUserId && $group->turnUserId->equals($viewer->viewerId);
            if ($isViewers ? null !== $after : ++$otherGroups > $otherGroupLimit) {
                continue;
            }
            $groups[] = $group;
        }

        return ['groups' => $groups, 'ticketCount' => $this->ticketCount, 'isSearchCapped' => $this->isSearchCapped];
    }

    /**
     * The viewer's group's count with no tickets, as a group holds whose-turn rows, not scored ones; a count of 0 when
     * the viewer has no group.
     */
    public function viewersTurnsOf(TicketViewer $viewer, int $limit, \DateTimeImmutable $now): ViewersTurns
    {
        $this->viewersTurnsLimits[] = $limit;
        foreach ($this->groups as $group) {
            if (null !== $group->turnUserId && $group->turnUserId->equals($viewer->viewerId)) {
                return new ViewersTurns([], $group->ticketCount);
            }
        }

        return new ViewersTurns([], 0);
    }
}
