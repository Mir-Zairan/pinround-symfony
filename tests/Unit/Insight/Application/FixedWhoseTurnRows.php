<?php

declare(strict_types=1);

namespace App\Tests\Unit\Insight\Application;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\TurnGroup;
use App\Insight\Application\WhoseTurnRows;

/**
 * Returns the groups it was given, in that order, as the database may return them in any order, and records each call.
 */
final class FixedWhoseTurnRows implements WhoseTurnRows
{
    /** @var list<array{?string, ?string}> the project id and opened group of each call */
    public array $calls = [];

    /**
     * @param list<TurnGroup> $groups
     */
    public function __construct(private readonly array $groups)
    {
    }

    public function groupedRowsOf(TicketViewer $viewer, ?string $projectId, ?string $openedGroup): array
    {
        $this->calls[] = [$projectId, $openedGroup];

        return $this->groups;
    }
}
