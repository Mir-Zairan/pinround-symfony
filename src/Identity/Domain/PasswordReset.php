<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A user's request to choose a new password, proven by the token in the emailed link. It works once and for one hour
 * (FR-ID-08); PasswordResets::consume uses it up. Global, like the user.
 */
final class PasswordReset
{
    public const int LIFETIME_MINUTES = 60;
    private readonly string $tokenHash;
    private readonly \DateTimeImmutable $expiresAt;
    // Written only in the database, by PasswordResets::consume, so two requests with the same link cannot both use it.
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * @param string $id a UUID from PasswordResets::nextIdentity()
     */
    public function __construct(private readonly string $id, private readonly User $user, PasswordResetToken $token, private readonly \DateTimeImmutable $createdAt)
    {
        $this->tokenHash = $token->hash();
        $this->expiresAt = $createdAt->add(new \DateInterval('PT'.self::LIFETIME_MINUTES.'M'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->user->id();
    }

    /**
     * @return string the 32 raw bytes of the token's SHA-256
     */
    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }
}
