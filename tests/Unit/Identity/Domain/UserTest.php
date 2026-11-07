<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(Theme::System, $user->theme());
    }

    #[Test]
    public function aNewUserGetsARandomSessionStamp(): void
    {
        $first = new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $second = new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->sessionStamp());
        self::assertNotSame($first->sessionStamp(), $second->sessionStamp());
    }

    #[Test]
    public function rotatingTheSessionStampReplacesItWithANewRandomOne(): void
    {
        $user = new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $before = $user->sessionStamp();

        $user->rotateSessionStamp();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $user->sessionStamp());
        self::assertNotSame($before, $user->sessionStamp());
    }

    #[Test]
    public function itRefusesABlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(new UserId(self::ID), new Email('raj@acme-foods.example'), '   ', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oldIanaNamesBrowsersStillReport(): iterable
    {
        yield 'renamed city' => ['Asia/Calcutta'];
        yield 'renamed spelling' => ['Europe/Kiev'];
        yield 'US alias' => ['US/Pacific'];
    }

    #[Test]
    #[DataProvider('oldIanaNamesBrowsersStillReport')]
    public function itKeepsAnOldIanaNameAsGiven(string $timeZone): void
    {
        $user = new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', $timeZone, new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        self::assertSame($timeZone, $user->timeZone());
    }

    #[Test]
    public function itRefusesATimeZoneThatIsNotAnIanaIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', '+02:00', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }
}
