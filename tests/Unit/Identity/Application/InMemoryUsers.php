<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\EmailTaken;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * Users by id, recording every add. With $isEmailTakenOnAdd, add() refuses as another transaction's account with the
 * same email would, though byEmail() found none.
 */
final class InMemoryUsers implements Users
{
    /** @var array<string, User> */
    private array $byId = [];

    /** @var list<User> */
    public array $added = [];

    public bool $isEmailTakenOnAdd = false;

    private int $issuedIds = 0;

    public function __construct(User ...$users)
    {
        foreach ($users as $user) {
            $this->byId[$user->id()->toString()] = $user;
        }
    }

    public function nextIdentity(): UserId
    {
        ++$this->issuedIds;

        return new UserId(\sprintf('0197a3c0-0000-7000-8000-%012d', $this->issuedIds));
    }

    public function byId(UserId $id): ?User
    {
        return $this->byId[$id->toString()] ?? null;
    }

    public function byEmail(Email $email): ?User
    {
        foreach ($this->byId as $user) {
            if ($user->email()->equals($email)) {
                return $user;
            }
        }

        return null;
    }

    public function add(User $user): void
    {
        if ($this->isEmailTakenOnAdd) {
            throw new EmailTaken('Another user has this email.');
        }
        $this->byId[$user->id()->toString()] = $user;
        $this->added[] = $user;
    }

    public function save(User $user): void
    {
        throw new \LogicException('Not used.');
    }
}
