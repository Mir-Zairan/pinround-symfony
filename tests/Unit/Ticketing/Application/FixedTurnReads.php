<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Insight\Api\SavedTurn;
use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TeamLoads;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\RuleSetView;

/**
 * The reads SavedTicketPageTurns makes beyond the page: the ticket's saved turn (no waiting reviews or tests), the
 * team's rules, and the names Identity knows, each recording what it was asked. byIds leaves an unknown id out and
 * sorts by id, as the real one does.
 */
final class FixedTurnReads implements TeamLoadQueries, UserQueries, RuleQueries
{
    public ?SavedTurn $savedTurn = null;
    /** @var array<string, string> by user id string, the people Identity knows */
    public array $knownNames = [];

    /** @var list<list<string>> */
    public array $namesAskedFor = [];
    public int $loadReads = 0;

    public function waitingTurnsOf(array $userIds, TicketId $excludedTicketId): array
    {
        throw new \LogicException('The page turn reads the waiting turns with the saved turn.');
    }

    public function loadsAndSavedTurnOf(array $userIds, TicketId $ticketId): TeamLoads
    {
        ++$this->loadReads;

        return new TeamLoads([], $this->savedTurn);
    }

    public function rulesFor(TeamId $teamId): RuleSetView
    {
        return new RuleSetView(true, true, true);
    }

    public function idByEmail(string $email): ?UserId
    {
        throw new \LogicException('The page turn never looks a user up by email.');
    }

    public function byIds(array $userIds): array
    {
        $wanted = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->namesAskedFor[] = $wanted;
        $found = [];
        foreach ($this->knownNames as $userKey => $name) {
            if (\in_array($userKey, $wanted, true)) {
                $found[$userKey] = new UserView(new UserId($userKey), $name);
            }
        }
        ksort($found);

        return $found;
    }

    public function timeZonesOf(array $userIds): array
    {
        throw new \LogicException('The page turn never reads time zones.');
    }

    public function emailsOf(array $userIds): array
    {
        throw new \LogicException('The page turn never reads email addresses.');
    }
}
