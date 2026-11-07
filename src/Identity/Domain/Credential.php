<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * The password of a user, as a hash. The mapping makes the user its id, so user_id is both the primary key and the
 * foreign key to idn_user.
 */
final readonly class Credential
{
    private string $passwordHash;

    public function __construct(private User $user, PasswordHash $passwordHash, private \DateTimeImmutable $changedAt)
    {
        $this->passwordHash = $passwordHash->encoded();
    }

    public function userId(): UserId
    {
        return $this->user->id();
    }

    public function passwordHash(): PasswordHash
    {
        return new PasswordHash($this->passwordHash);
    }

    public function changedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
