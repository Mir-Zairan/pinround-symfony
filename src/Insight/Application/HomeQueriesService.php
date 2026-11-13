<?php

declare(strict_types=1);

namespace App\Insight\Application;

use App\Insight\Api\HomeQueries;
use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Ticketing\Api\ListFilter;

final readonly class HomeQueriesService implements HomeQueries
{
    private const int OPENED_GROUP_MAX_LENGTH = 100;

    public function __construct(private WhoseTurnRows $whoseTurnRows)
    {
    }

    public function whoseTurn(TicketViewer $viewer, ?string $projectId, ?string $openedGroup): array
    {
        if (null !== $projectId && !ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('Whose turn is read in a project given by its canonical lowercase uuid.');
        }
        if (null !== $openedGroup
            && (!mb_check_encoding($openedGroup, 'UTF-8') || mb_strlen($openedGroup, 'UTF-8') > self::OPENED_GROUP_MAX_LENGTH)) {
            throw new \InvalidArgumentException('The opened whose-turn group is a user id or a label of at most 100 characters.');
        }

        $groups = $this->whoseTurnRows->groupedRowsOf($viewer, $projectId, $openedGroup);
        $viewerId = $viewer->viewerId;
        usort($groups, static fn (TurnGroup $one, TurnGroup $other): int => [
            null !== $other->turnUserId && $other->turnUserId->equals($viewerId),
            $one->oldestWaitingSince,
            null === $one->turnUserId,
            $one->groupKey(),
        ] <=> [
            null !== $one->turnUserId && $one->turnUserId->equals($viewerId),
            $other->oldestWaitingSince,
            null === $other->turnUserId,
            $other->groupKey(),
        ]);

        return $groups;
    }
}
