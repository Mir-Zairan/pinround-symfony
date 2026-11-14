<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A comment or a question in a ticket's thread. A question names the person asked and stays open until an answer
 * closes it, which sets resolvedAt, or its asker withdraws it, which sets removedAt; either way the open-question
 * indexes drop it. A ticket has at most one open question that is
 * not removed (cnv_message_ticket_id_open_question_key). Only a comment is folded by Resolve (FR-CNV-05).
 */
final class Message implements TenantOwned
{
    // No requirement gives a limit, so this one is chosen; cnv_message_body_check holds the same.
    public const int MAX_BODY_LENGTH = 10000;

    // FR-CNV-06: the author edits a message up to 15 minutes after posting it; removing has no time limit.
    public const int EDIT_WINDOW_MINUTES = 15;

    // Kept as strings and wrapped on the way out, like Invitation: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $ticketId;
    private readonly string $authorId;
    private readonly ?string $toUserId;
    private ?\DateTimeImmutable $resolvedAt = null;
    private ?string $resolvedBy = null;
    private ?\DateTimeImmutable $editedAt = null;
    private ?\DateTimeImmutable $removedAt = null;

    private function __construct(MessageId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $authorId, private readonly MessageKind $kind, ?UserId $toUserId, private string $body, private readonly \DateTimeImmutable $createdAt)
    {
        self::refuseAnInvalidBody($body);

        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->ticketId = $ticketId->toString();
        $this->authorId = $authorId->toString();
        $this->toUserId = $toUserId?->toString();
    }

    /**
     * @throws \InvalidArgumentException when the body is blank or longer than MAX_BODY_LENGTH characters
     */
    public static function comment(MessageId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $authorId, string $body, \DateTimeImmutable $createdAt): self
    {
        return new self($id, $organizationId, $ticketId, $authorId, MessageKind::Comment, null, $body, $createdAt);
    }

    /**
     * An open question to one person.
     *
     * @throws \InvalidArgumentException when the body is blank or longer than MAX_BODY_LENGTH characters
     */
    public static function question(MessageId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $authorId, UserId $toUserId, string $body, \DateTimeImmutable $createdAt): self
    {
        return new self($id, $organizationId, $ticketId, $authorId, MessageKind::Question, $toUserId, $body, $createdAt);
    }

    /**
     * Replaces the body and marks the message edited. Up to and including EDIT_WINDOW_MINUTES after createdAt.
     *
     * @throws \DomainException          when the message is removed, the editor is not its author, or the edit window
     *                                   has passed; the message stays as it was
     * @throws \InvalidArgumentException when the body is blank or longer than MAX_BODY_LENGTH characters
     */
    public function edit(UserId $editorId, string $body, \DateTimeImmutable $editedAt): void
    {
        if (null !== $this->removedAt) {
            throw new \DomainException('This message was removed, so it cannot be edited.');
        }
        if (!$editorId->equals($this->authorId())) {
            throw new \DomainException('Only the person who wrote a message can edit it.');
        }
        if ($editedAt > $this->createdAt->modify(\sprintf('+%d minutes', self::EDIT_WINDOW_MINUTES))) {
            throw new \DomainException(\sprintf('You can edit a message for %d minutes after you post it.', self::EDIT_WINDOW_MINUTES));
        }
        self::refuseAnInvalidBody($body);

        $this->body = $body;
        $this->editedAt = $editedAt;
    }

    /**
     * Marks the message removed; its row stays, so the thread shows "Message removed" where it was. Any time after
     * posting.
     *
     * @throws \DomainException when the message is already removed or the remover is not its author; the message stays
     *                          as it was
     */
    public function remove(UserId $removerId, \DateTimeImmutable $removedAt): void
    {
        if (null !== $this->removedAt) {
            throw new \DomainException('This message is already removed.');
        }
        if (!$removerId->equals($this->authorId())) {
            throw new \DomainException('Only the person who wrote a message can remove it.');
        }

        $this->removedAt = $removedAt;
    }

    /**
     * Closes an open question: sets resolvedAt and resolvedBy, so the open-question indexes drop it.
     *
     * @throws \DomainException when the message is a comment, the question is withdrawn or already answered, or the
     *                          answerer is not the person asked; the message stays as it was
     */
    public function answer(UserId $answererId, \DateTimeImmutable $answeredAt): void
    {
        if (MessageKind::Question !== $this->kind) {
            throw new \DomainException('Only a question can be answered.');
        }
        if (null !== $this->removedAt) {
            throw new \DomainException('This question was withdrawn.');
        }
        if (null !== $this->resolvedAt) {
            throw new \DomainException('This question is already answered.');
        }
        if (null === $this->toUserId || !$answererId->equals(new UserId($this->toUserId))) {
            throw new \DomainException('Only the person asked can answer this question.');
        }

        $this->resolvedAt = $answeredAt;
        $this->resolvedBy = $answererId->toString();
    }

    /**
     * The asker takes back their open question: marks it removed, so the open-question indexes drop it and the thread
     * shows "Message removed" where it was (workflow-engine.md, "or the asker withdraws it").
     *
     * @throws \DomainException when the message is a comment, the question is already withdrawn or answered, or the
     *                          withdrawer is not its asker; the message stays as it was
     */
    public function withdraw(UserId $withdrawerId, \DateTimeImmutable $withdrawnAt): void
    {
        if (MessageKind::Question !== $this->kind) {
            throw new \DomainException('Only a question can be withdrawn.');
        }
        if (null !== $this->removedAt) {
            throw new \DomainException('This question is already withdrawn.');
        }
        if (null !== $this->resolvedAt) {
            throw new \DomainException('An answered question cannot be withdrawn.');
        }
        if (!$withdrawerId->equals($this->authorId())) {
            throw new \DomainException('Only the person who asked a question can withdraw it.');
        }

        $this->removedAt = $withdrawnAt;
    }

    /**
     * Folds a comment for everyone (FR-CNV-05): sets resolvedAt and resolvedBy. A question is closed only by answer().
     *
     * @param bool $isTeamLead whether $resolverId leads the ticket's team, who may resolve anyone's comment there
     *
     * @throws \DomainException when the message is a question, the comment is removed or already resolved, or the
     *                          resolver is neither its author nor the team's lead; the message stays as it was
     */
    public function resolve(UserId $resolverId, bool $isTeamLead, \DateTimeImmutable $resolvedAt): void
    {
        if (MessageKind::Comment !== $this->kind) {
            throw new \DomainException('A question is closed by its answer, not by Resolve.');
        }
        if (null !== $this->removedAt) {
            throw new \DomainException('This comment was removed, so it cannot be resolved.');
        }
        if (null !== $this->resolvedAt) {
            throw new \DomainException('This comment is already resolved.');
        }
        if (!$isTeamLead && !$resolverId->equals($this->authorId())) {
            throw new \DomainException('Only the person who wrote a comment or the lead of the ticket\'s team can resolve it.');
        }

        $this->resolvedAt = $resolvedAt;
        $this->resolvedBy = $resolverId->toString();
    }

    /**
     * @throws \InvalidArgumentException when the body is blank or longer than MAX_BODY_LENGTH characters
     */
    private static function refuseAnInvalidBody(string $body): void
    {
        if ('' === trim($body)) {
            throw new \InvalidArgumentException('A message needs a body.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A message can have at most %d characters.', self::MAX_BODY_LENGTH));
        }
    }

    public function id(): MessageId
    {
        return new MessageId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function ticketId(): TicketId
    {
        return new TicketId($this->ticketId);
    }

    public function authorId(): UserId
    {
        return new UserId($this->authorId);
    }

    public function kind(): MessageKind
    {
        return $this->kind;
    }

    /**
     * The person a question asks; null for a comment.
     */
    public function toUserId(): ?UserId
    {
        return null === $this->toUserId ? null : new UserId($this->toUserId);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function resolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolvedBy(): ?UserId
    {
        return null === $this->resolvedBy ? null : new UserId($this->resolvedBy);
    }

    public function editedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function removedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
