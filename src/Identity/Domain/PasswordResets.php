<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

interface PasswordResets
{
    /**
     * A new id no reset has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): string;

    /**
     * Saves the reset at once.
     */
    public function add(PasswordReset $reset): void;

    /**
     * Whether a reset with this token exists, is unused and has not expired at $now. Reads only: the link can still
     * be used up by another request after this answers.
     */
    public function isUsable(PasswordResetToken $token, \DateTimeImmutable $now): bool;

    /**
     * Marks the reset with this token used at $now and returns its user, in one statement, so of two requests with the
     * same token at most one gets the user.
     *
     * @return UserId|null null when no reset has this token, or it is used, or it has expired at $now
     */
    public function consume(PasswordResetToken $token, \DateTimeImmutable $now): ?UserId;
}
