<?php

declare(strict_types=1);

namespace App\Conversation\Application\RemoveMessage;

use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\MessageRemovalRefused;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\MessageKind;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The author removes their comment at any time (FR-CNV-06); the thread keeps a "Message removed" line where it was.
 * Publishes no event: the contract has none for a removal, and a removal notifies nobody.
 */
final readonly class RemoveMessage
{
    public function __construct(private ThreadMessages $threadMessages, private Messages $messages)
    {
    }

    /**
     * Decides on the message's locked row. Needs an open transaction whose tenant is $organizationId.
     *
     * @throws MessageNotFound       when the id is malformed or names no message of that ticket; nothing changes
     * @throws MessageRemovalRefused when the message is a question, already removed, or someone else's; nothing changes
     */
    public function remove(OrganizationId $organizationId, TicketId $ticketId, string $messageId, UserId $removerId, \DateTimeImmutable $removedAt): void
    {
        $message = $this->threadMessages->lockedOf($organizationId, $ticketId, $messageId);
        // TODO(P04.T2): removing an open question must also give the turn back; until then a question is not removed.
        if (MessageKind::Comment !== $message->kind()) {
            throw new MessageRemovalRefused('A question cannot be removed.');
        }

        try {
            $message->remove($removerId, $removedAt);
        } catch (\DomainException $refusal) {
            throw new MessageRemovalRefused($refusal->getMessage(), 0, $refusal);
        }
        $this->messages->save($message);
    }
}
