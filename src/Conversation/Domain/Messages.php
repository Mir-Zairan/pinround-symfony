<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * The comments and questions of tickets' threads. Each method needs an open transaction under the message's
 * organization.
 */
interface Messages
{
    public function nextIdentity(): MessageId;

    /**
     * Saves the new message at once.
     *
     * @throws QuestionAlreadyOpen when the message is a question and its ticket has an open question already; the
     *                             transaction can no longer commit, so the caller rolls back
     */
    public function add(Message $message): void;

    /**
     * Reads the message and locks its row until the transaction ends, so a change decided on it cannot race another.
     * A message this transaction read already is locked and returned as the same object, not read again: its unsaved
     * changes stay, and a change another transaction committed meanwhile is not seen.
     *
     * @return Message|null null when the organization has no message with that id
     */
    public function lockedOf(OrganizationId $organizationId, MessageId $id): ?Message;

    /**
     * Writes the changes of a message read in this transaction.
     *
     * @throws \LogicException when the message was not read in this transaction; a new one is added
     */
    public function save(Message $message): void;
}
