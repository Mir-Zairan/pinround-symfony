<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * Holds at most one user, found by any id, and records every save.
 */
final class RecordingUsers implements Users
{
    /** @var list<User> */
    public array $savedUsers = [];

    public function __construct(private readonly ?User $user)
    {
    }

    public function nextIdentity(): UserId
    {
        throw new \LogicException('Not used.');
    }

    public function byId(UserId $id): ?User
    {
        return $this->user;
    }

    public function byEmail(Email $email): ?User
    {
        throw new \LogicException('Not used.');
    }

    public function add(User $user): void
    {
        throw new \LogicException('Not used.');
    }

    public function save(User $user): void
    {
        $this->savedUsers[] = $user;
    }
}
