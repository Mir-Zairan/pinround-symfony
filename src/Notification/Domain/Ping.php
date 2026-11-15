<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A short nudge from one person to another about a ticket. It shows from deliverAfter on (the end of the recipient's
 * quiet hours) or at once when that is null, and stays in the tray until it is cleared.
 */
final class Ping implements TenantOwned
{
    public const int MAX_BODY_LENGTH = 500;

    // Kept as strings and wrapped on the way out, like Invitation: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $ticketId;
    private readonly string $fromId;
    private readonly string $toId;
    private ?\DateTimeImmutable $clearedAt = null;
    private ?PingClearedReason $clearedReason = null;

    private function __construct(PingId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $fromId, UserId $toId, private readonly string $body, private readonly ?\DateTimeImmutable $deliverAfter, private readonly \DateTimeImmutable $createdAt)
    {
        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->ticketId = $ticketId->toString();
        $this->fromId = $fromId->toString();
        $this->toId = $toId->toString();
    }

    /**
     * @param string $body 1 to MAX_BODY_LENGTH characters after trimming, as ntf_ping_body_check counts them
     *
     * @throws \InvalidArgumentException when the trimmed body is empty or longer than MAX_BODY_LENGTH characters
     */
    public static function send(PingId $id, OrganizationId $organizationId, TicketId $ticketId, UserId $fromId, UserId $toId, string $body, ?\DateTimeImmutable $deliverAfter, \DateTimeImmutable $createdAt): self
    {
        $trimmedBody = trim($body);
        $length = mb_strlen($trimmedBody);
        if (0 === $length || $length > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A ping needs a message of 1 to %d characters.', self::MAX_BODY_LENGTH));
        }

        return new self($id, $organizationId, $ticketId, $fromId, $toId, $trimmedBody, $deliverAfter, $createdAt);
    }

    /**
     * Takes the ping out of the tray for good.
     *
     * @throws \LogicException when the ping is cleared already; it keeps its first clearing
     */
    public function clear(PingClearedReason $reason, \DateTimeImmutable $at): void
    {
        if (null !== $this->clearedAt) {
            throw new \LogicException('The ping is cleared already.');
        }

        $this->clearedAt = $at;
        $this->clearedReason = $reason;
    }

    public function id(): PingId
    {
        return new PingId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function ticketId(): TicketId
    {
        return new TicketId($this->ticketId);
    }

    public function fromId(): UserId
    {
        return new UserId($this->fromId);
    }

    public function toId(): UserId
    {
        return new UserId($this->toId);
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * When the ping shows, the end of the recipient's quiet hours; null when it showed at once.
     */
    public function deliverAfter(): ?\DateTimeImmutable
    {
        return $this->deliverAfter;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function clearedAt(): ?\DateTimeImmutable
    {
        return $this->clearedAt;
    }

    public function clearedReason(): ?PingClearedReason
    {
        return $this->clearedReason;
    }
}
