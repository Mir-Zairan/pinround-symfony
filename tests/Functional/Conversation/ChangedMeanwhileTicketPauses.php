<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketPauses;

/**
 * TicketPauses when another request saved the ticket after this one read it: the real one refuses before it saves.
 */
final class ChangedMeanwhileTicketPauses implements TicketPauses
{
    public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void
    {
        throw new TicketChangedMeanwhile('The ticket changed meanwhile.');
    }

    public function endQuestionPause(TicketId $ticketId): void
    {
        throw new TicketChangedMeanwhile('The ticket changed meanwhile.');
    }
}
