<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketAlreadyPaused;
use App\Ticketing\Api\TicketPauses;

/**
 * The question pause by ticket id. As the real port, a paused ticket refuses a second question and ending a pause the
 * ticket does not have is refused; unlike it, everyone asked counts as on the ticket.
 */
final class InMemoryTicketPauses implements TicketPauses
{
    /** @var array<string, string> ids of the tickets a question pauses */
    private array $pausedTicketIds = [];

    public function __construct(string ...$pausedTicketIds)
    {
        foreach ($pausedTicketIds as $ticketId) {
            $this->pausedTicketIds[$ticketId] = $ticketId;
        }
    }

    public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void
    {
        if (isset($this->pausedTicketIds[$ticketId->toString()])) {
            throw new TicketAlreadyPaused('Another question already pauses this ticket.');
        }

        $this->pausedTicketIds[$ticketId->toString()] = $ticketId->toString();
    }

    public function endQuestionPause(TicketId $ticketId): void
    {
        if (!isset($this->pausedTicketIds[$ticketId->toString()])) {
            throw new \LogicException('The ticket waits for no answer.');
        }

        unset($this->pausedTicketIds[$ticketId->toString()]);
    }
}
