<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A reply under a comment or question of the same organization. An answer is a reply that answers the question.
 */
final class Reply implements TenantOwned
{
    // Kept as strings and wrapped on the way out, like Invitation: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $messageId;
    private readonly string $authorId;
    private ?\DateTimeImmutable $editedAt = null;
    private ?\DateTimeImmutable $removedAt = null;

    private function __construct(ReplyId $id, OrganizationId $organizationId, MessageId $messageId, UserId $authorId, private string $body, private readonly bool $isAnswer, private readonly \DateTimeImmutable $createdAt)
    {
        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->messageId = $messageId->toString();
        $this->authorId = $authorId->toString();
    }

    /**
     * @throws \InvalidArgumentException when the body is blank
     */
    public static function post(ReplyId $id, OrganizationId $organizationId, MessageId $messageId, UserId $authorId, string $body, bool $isAnswer, \DateTimeImmutable $createdAt): self
    {
        if ('' === trim($body)) {
            throw new \InvalidArgumentException('A reply needs a body.');
        }

        return new self($id, $organizationId, $messageId, $authorId, $body, $isAnswer, $createdAt);
    }

    public function id(): ReplyId
    {
        return new ReplyId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function messageId(): MessageId
    {
        return new MessageId($this->messageId);
    }

    public function authorId(): UserId
    {
        return new UserId($this->authorId);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isAnswer(): bool
    {
        return $this->isAnswer;
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
