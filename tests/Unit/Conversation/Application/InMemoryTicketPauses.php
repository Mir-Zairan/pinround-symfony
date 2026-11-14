<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketAlreadyPaused;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPauses;

/**
 * The question pause of the tickets it is given, by id. As LockedTicketPauses, with the same exception classes and
 * messages, in the same order: an unknown ticket is not found, a paused ticket refuses a second question, nobody asks
 * themselves, and ending a pause the ticket does not have is refused. Unlike it, everyone asked counts as on the ticket.
 */
final class InMemoryTicketPauses implements TicketPauses
{
    /** @var array<string, bool> whether a question pauses the ticket, by ticket id */
    private array $isPausedByTicketId = [];

    /**
     * @param list<string> $ticketIds       the tickets of the organization
     * @param list<string> $pausedTicketIds those of them a question already pauses
     */
    public function __construct(array $ticketIds, array $pausedTicketIds = [])
    {
        foreach ($ticketIds as $ticketId) {
            $this->isPausedByTicketId[$ticketId] = false;
        }
        foreach ($pausedTicketIds as $ticketId) {
            if (!isset($this->isPausedByTicketId[$ticketId])) {
                throw new \InvalidArgumentException('A paused ticket must be one of the tickets.');
            }
            $this->isPausedByTicketId[$ticketId] = true;
        }
    }

    public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void
    {
        if ($this->isPaused($ticketId)) {
            throw new TicketAlreadyPaused('This ticket already waits for an answer.');
        }
        if ($askedUserId->equals($askerId)) {
            throw new \InvalidArgumentException('A person cannot ask themselves a question.');
        }

        $this->isPausedByTicketId[$ticketId->toString()] = true;
    }

    public function endQuestionPause(TicketId $ticketId): void
    {
        if (!$this->isPaused($ticketId)) {
            throw new \LogicException('This ticket waits for no answer.');
        }

        $this->isPausedByTicketId[$ticketId->toString()] = false;
    }

    /**
     * @return list<string> ids of the tickets a question pauses, in the order the tickets were given
     */
    public function pausedTicketIds(): array
    {
        return array_keys(array_filter($this->isPausedByTicketId));
    }

    private function isPaused(TicketId $ticketId): bool
    {
        return $this->isPausedByTicketId[$ticketId->toString()]
            ?? throw new TicketNotFound('The organization has no ticket with this id.');
    }
}
