<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * An invitation not yet accepted and not yet expired, as the admin page of invitations lists it.
 */
final readonly class PendingInvitation
{
    /**
     * @param string $id the invitation's uuid
     */
    public function __construct(
        private string $id,
        private string $email,
        private \DateTimeImmutable $invitedAt,
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function invitedAt(): \DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
