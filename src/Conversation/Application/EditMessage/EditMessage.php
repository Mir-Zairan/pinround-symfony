<?php

declare(strict_types=1);

namespace App\Conversation\Application\EditMessage;

use App\Conversation\Api\Event\MessageEdited;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageEditRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Domain\MessageKind;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The author changes the text of their comment, up to 15 minutes after posting it (FR-CNV-06); the thread then marks
 * it "edited". An edit notifies nobody; its MessageEdited refreshes the pages open on the ticket.
 */
final readonly class EditMessage
{
    public function __construct(
        private ThreadMessages $threadMessages,
        private Messages $messages,
        private ConversationEvents $conversationEvents,
    ) {
    }

    /**
     * Decides on the message's locked row, so two edits, or an edit and a removal, cannot both pass the check, then
     * saves the edit and writes MessageEdited to the outbox, both in the open transaction, whose tenant must be
     * $organizationId.
     *
     * @throws MessageNotFound    when the id is malformed or names no message of that ticket; nothing changes
     * @throws MessageEditRefused when the message is a question, removed, someone else's, or older than 15 minutes;
     *                            nothing changes
     * @throws MessageBodyRefused when the trimmed body is empty or longer than 10,000 characters; nothing changes
     * @throws \LogicException    when no transaction is open; nothing is saved
     */
    public function edit(OrganizationId $organizationId, TicketId $ticketId, string $messageId, UserId $editorId, string $body, \DateTimeImmutable $editedAt): void
    {
        $message = $this->threadMessages->lockedOf($organizationId, $ticketId, $messageId);
        // TODO(P04.T2): decide whether a question may be edited, and what an edited open question means for its turn.
        if (MessageKind::Comment !== $message->kind()) {
            throw new MessageEditRefused('A question cannot be edited.');
        }

        try {
            $message->edit($editorId, trim($body), $editedAt);
        } catch (\InvalidArgumentException $invalidBody) {
            throw new MessageBodyRefused($invalidBody->getMessage(), 0, $invalidBody);
        } catch (\DomainException $refusal) {
            throw new MessageEditRefused($refusal->getMessage(), 0, $refusal);
        }
        // Published before the save, as PostComment does: publish refuses when no transaction is open.
        $this->conversationEvents->publish(new MessageEdited(
            $organizationId->toString(),
            $ticketId->toString(),
            $message->id()->toString(),
            $editorId->toString(),
            $editedAt,
        ));
        $this->messages->save($message);
    }
}
