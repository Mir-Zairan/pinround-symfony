<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ChangeTheme;
use App\Identity\Domain\Email;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeThemeTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-0000000000c1';

    #[Test]
    public function itSavesTheThemeAndKeepsTheSessionStamp(): void
    {
        $user = self::omar();
        $stampBefore = $user->sessionStamp();
        $users = new RecordingUsers($user);

        self::assertTrue(new ChangeTheme($users)(new UserId(self::ID), 'dark'));

        self::assertSame(Theme::Dark, $user->theme());
        self::assertSame($stampBefore, $user->sessionStamp());
        self::assertSame([$user], $users->savedUsers);
    }

    #[Test]
    public function anUnknownThemeSavesNothing(): void
    {
        $user = self::omar();
        $users = new RecordingUsers($user);

        self::assertFalse(new ChangeTheme($users)(new UserId(self::ID), 'Dark'));

        self::assertSame(Theme::System, $user->theme());
        self::assertSame([], $users->savedUsers);
    }

    #[Test]
    public function anUnknownUserIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        new ChangeTheme(new RecordingUsers(null))(new UserId(self::ID), 'dark');
    }

    private static function omar(): User
    {
        return new User(new UserId(self::ID), new Email('omar@acme-foods.example'), 'Omar Haddad', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }
}
