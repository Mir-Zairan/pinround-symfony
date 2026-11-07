<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Profile;
use App\Identity\Application\ProfileProblem;
use App\Identity\Application\UpdateProfile;
use App\Identity\Domain\Email;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateProfileTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-0000000000c1';

    #[Test]
    public function itSavesTheTrimmedNameTheTimeZoneAndTheThemeAndKeepsTheSessionStamp(): void
    {
        $user = self::lena();
        $stampBefore = $user->sessionStamp();
        $users = new RecordingUsers($user);

        $problems = new UpdateProfile($users)(new UserId(self::ID), '  Lena Lund ', 'Asia/Kolkata', 'dark');

        self::assertSame([], $problems);
        self::assertSame('Lena Lund', $user->name());
        self::assertSame('Asia/Kolkata', $user->timeZone());
        self::assertSame(Theme::Dark, $user->theme());
        self::assertSame($stampBefore, $user->sessionStamp());
        self::assertSame([$user], $users->savedUsers);
    }

    /**
     * @return iterable<string, array{string, string, string, list<ProfileProblem>}>
     */
    public static function refusedInput(): iterable
    {
        yield 'blank name' => [' ', 'Asia/Kolkata', 'dark', [ProfileProblem::NameBlank]];
        yield 'unknown time zone' => ['Lena Lund', 'Mars/Olympus', 'dark', [ProfileProblem::TimeZoneUnknown]];
        yield 'unknown theme' => ['Lena Lund', 'Asia/Kolkata', 'purple', [ProfileProblem::ThemeUnknown]];
        yield 'all three' => ['', '', '', [ProfileProblem::NameBlank, ProfileProblem::TimeZoneUnknown, ProfileProblem::ThemeUnknown]];
    }

    /**
     * @param list<ProfileProblem> $expectedProblems
     */
    #[Test]
    #[DataProvider('refusedInput')]
    public function oneWrongFieldChangesNoFieldAndSavesNothing(string $name, string $timeZone, string $theme, array $expectedProblems): void
    {
        $user = self::lena();
        $users = new RecordingUsers($user);

        $problems = new UpdateProfile($users)(new UserId(self::ID), $name, $timeZone, $theme);

        self::assertSame($expectedProblems, $problems);
        self::assertSame('Lena Berg', $user->name());
        self::assertSame('Europe/Berlin', $user->timeZone());
        self::assertSame(Theme::System, $user->theme());
        self::assertSame([], $users->savedUsers);
    }

    #[Test]
    public function itShowsTheSavedProfile(): void
    {
        $user = self::lena();
        $user->changeTheme(Theme::Light);

        self::assertEquals(new Profile('Lena Berg', 'Europe/Berlin', 'light'), new UpdateProfile(new RecordingUsers($user))->current(new UserId(self::ID)));
    }

    #[Test]
    public function anUnknownUserIsAProgrammingError(): void
    {
        $users = new RecordingUsers(null);

        try {
            new UpdateProfile($users)(new UserId(self::ID), 'Lena Lund', 'Asia/Kolkata', 'dark');
            self::fail('An unknown user was saved.');
        } catch (\LogicException) {
        }
        self::assertSame([], $users->savedUsers);

        $this->expectException(\LogicException::class);
        new UpdateProfile($users)->current(new UserId(self::ID));
    }

    private static function lena(): User
    {
        return new User(new UserId(self::ID), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }
}
