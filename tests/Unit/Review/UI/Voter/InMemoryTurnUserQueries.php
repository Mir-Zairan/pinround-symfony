<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Voter;

use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Domain\UserId;

/**
 * Like the real queries: the turn user by ticket id string, leaving out a ticket whose turn is a label's or that has
 * no turn row; no read for an empty list; a LogicException, before any read, while "no transaction is open". Records
 * every ticket it is asked for.
 */
final class InMemoryTurnUserQueries implements TurnUserQueries
{
    /** @var list<string> */
    public array $askedTicketIds = [];

    /**
     * @param array<string, string|null> $turnUserIdsByTicketId the turn user id by ticket id; null for a label's turn
     */
    public function __construct(private readonly array $turnUserIdsByTicketId, private readonly bool $isTransactionOpen = true)
    {
    }

    public function turnUsersOf(array $ticketIds): array
    {
        if ([] === $ticketIds) {
            return [];
        }
        if (!$this->isTransactionOpen) {
            throw new \LogicException('Turn users are read in a transaction under the organization as tenant; none is open.');
        }

        $turnUsers = [];
        foreach ($ticketIds as $ticketId) {
            $this->askedTicketIds[] = $ticketId->toString();
            $turnUserId = $this->turnUserIdsByTicketId[$ticketId->toString()] ?? null;
            if (null !== $turnUserId) {
                $turnUsers[$ticketId->toString()] = new UserId($turnUserId);
            }
        }

        return $turnUsers;
    }

    public function ticketIdsWaitingOn(UserId $userId, int $max): array
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('At least one ticket id must be asked for.');
        }
        if (!$this->isTransactionOpen) {
            throw new \LogicException('Turn users are read in a transaction under the organization as tenant; none is open.');
        }
        $ticketIds = array_keys(array_filter($this->turnUserIdsByTicketId, static fn (?string $turnUserId): bool => $turnUserId === $userId->toString()));
        sort($ticketIds);

        return \array_slice(array_map(strval(...), $ticketIds), 0, $max);
    }
}
