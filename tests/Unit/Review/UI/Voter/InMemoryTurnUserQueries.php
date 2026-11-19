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
}
