<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\LogOutEverywhere;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogOutEverywhereTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-0000000000c1';

    /** @var list<User> */
    private array $savedUsers = [];

    #[Test]
    public function itRotatesTheSessionStampAndSavesTheUser(): void
    {
        $user = new User(new UserId(self::ID), new Email('sara@acme-foods.example'), 'Sara Holm', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $stampBefore = $user->sessionStamp();

        new LogOutEverywhere($this->users($user))(new UserId(self::ID));

        self::assertNotSame($stampBefore, $user->sessionStamp());
        self::assertSame([$user], $this->savedUsers);
    }

    #[Test]
    public function anUnknownUserSavesNothing(): void
    {
        new LogOutEverywhere($this->users(null))(new UserId(self::ID));

        self::assertSame([], $this->savedUsers);
    }

    private function users(?User $user): Users
    {
        $recordSave = function (User $saved): void {
            $this->savedUsers[] = $saved;
        };

        return new readonly class($user, $recordSave) implements Users {
            /**
             * @param \Closure(User): void $recordSave
             */
            public function __construct(private ?User $user, private \Closure $recordSave)
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
                ($this->recordSave)($user);
            }
        };
    }
}
