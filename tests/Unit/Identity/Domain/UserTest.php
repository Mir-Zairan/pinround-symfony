<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-0000000000c1';

    #[Test]
    public function aNewUserIsActiveAndKeepsWhatItWasGiven(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-02 09:30:00 UTC');
        $user = new User(new UserId(self::ID), new Email('Raj@Acme-Foods.Example'), '  Raj  ', 'en', 'Asia/Kolkata', $createdAt);

        self::assertSame(self::ID, $user->id()->toString());
        self::assertSame('raj@acme-foods.example', $user->email()->toString());
        self::assertSame('Raj', $user->name());
        self::assertSame(UserStatus::Active, $user->status());
        self::assertSame('en', $user->locale());
        self::assertSame('Asia/Kolkata', $user->timeZone());
        self::assertSame($createdAt, $user->createdAt());
    }

    #[Test]
    public function itRefusesABlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(new UserId(self::ID), new Email('raj@acme-foods.example'), '   ', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    #[Test]
    public function itRefusesATimeZoneThatIsNotAnIanaIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', '+02:00', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }
}
