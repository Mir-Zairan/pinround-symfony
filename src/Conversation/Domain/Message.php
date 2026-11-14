<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A comment or a question in a ticket's thread. A question names the person asked and stays open until an answer
 * closes it, which sets resolvedAt, so the open-question indexes drop it; a ticket has at most one open question that is
 * not removed (cnv_message_ticket_id_open_question_key). Only a comment is folded by Resolve (FR-CNV-05).
 */
final class Message implements TenantOwned
{
    // No requirement gives a limit, so this one is chosen; cnv_message_body_check holds the same.
    public const int MAX_BODY_LENGTH = 10000;

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
        if ('' === trim($body)) {
            throw new \InvalidArgumentException('A message needs a body.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A message can have at most %d characters.', self::MAX_BODY_LENGTH));
        }

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
