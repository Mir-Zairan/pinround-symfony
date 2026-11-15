<?php

declare(strict_types=1);

namespace App\Conversation\Application;

use App\Conversation\Api\MessageNotFound;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;

/**
 * Finds a message of one ticket's thread for a change, so a message id is never acted on through another ticket's
 * page, whose visibility the caller checked.
 */
final readonly class ThreadMessages
{
    public function __construct(private Messages $messages)
    {
    }

    /**
     * Locks the message's row until the transaction ends.
     *
     * @throws MessageNotFound when the id is malformed, or the organization has no message with that id on the ticket
     */
    public function lockedOf(OrganizationId $organizationId, TicketId $ticketId, string $messageId): Message
    {
        try {
            $id = new MessageId($messageId);
        } catch (\InvalidArgumentException $malformedId) {
            throw new MessageNotFound('That message does not exist.', 0, $malformedId);
        }
        $message = $this->messages->lockedOf($organizationId, $id);
        if (null === $message || !$message->ticketId()->equals($ticketId)) {
            throw new MessageNotFound('That message does not exist.');
        }

        return $message;
    }
}
