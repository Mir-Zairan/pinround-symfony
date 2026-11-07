<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\BlankUserName;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidProfile;
use App\Identity\Domain\Theme;
use App\Identity\Domain\UnknownTheme;
use App\Identity\Domain\UnknownTimeZone;
use App\Identity\Domain\User;
use App\Identity\Domain\UserNameTooLong;
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
        $this->expectException(BlankUserName::class);

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
        $this->expectException(UnknownTimeZone::class);

        new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', '+02:00', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    #[Test]
    public function aNameOfAHundredCharactersIsKeptAndOneMoreIsRefused(): void
    {
        $hundred = str_repeat('é', 100);
        $user = new User(new UserId(self::ID), new Email('raj@acme-foods.example'), ' '.$hundred.' ', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        self::assertSame($hundred, $user->name());

        $this->expectException(UserNameTooLong::class);
        new User(new UserId(self::ID), new Email('raj@acme-foods.example'), $hundred.'é', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    #[Test]
    public function changingTheProfileKeepsTheTrimmedNameTheOldIanaNameTheThemeAndTheSessionStamp(): void
    {
        $user = self::raj();
        $stampBefore = $user->sessionStamp();

        $user->changeProfile("  Raj Kumar \t", 'Asia/Calcutta', 'dark');

        self::assertSame('Raj Kumar', $user->name());
        self::assertSame('Asia/Calcutta', $user->timeZone());
        self::assertSame(Theme::Dark, $user->theme());
        self::assertSame($stampBefore, $user->sessionStamp());
    }

    /**
     * @return iterable<string, array{string, string, string, list<class-string>}>
     */
    public static function refusedProfiles(): iterable
    {
        yield 'blank name' => [" \n ", 'Asia/Calcutta', 'dark', [BlankUserName::class]];
        yield 'name of 101 characters' => [str_repeat('a', 101), 'Asia/Calcutta', 'dark', [UserNameTooLong::class]];
        yield 'offset instead of a time zone' => ['Raj Kumar', '+02:00', 'dark', [UnknownTimeZone::class]];
        yield 'theme in another case' => ['Raj Kumar', 'Asia/Calcutta', 'Dark', [UnknownTheme::class]];
        yield 'all three' => ['', 'Mars/Olympus', 'purple', [BlankUserName::class, UnknownTimeZone::class, UnknownTheme::class]];
    }

    /**
     * @param list<class-string> $expectedRefusals
     */
    #[Test]
    #[DataProvider('refusedProfiles')]
    public function aRefusedFieldChangesNoFieldAndEveryRefusalIsReported(string $name, string $timeZone, string $theme, array $expectedRefusals): void
    {
        $user = self::raj();

        try {
            $user->changeProfile($name, $timeZone, $theme);
            self::fail('A wrong profile was accepted.');
        } catch (InvalidProfile $invalidProfile) {
            self::assertSame($expectedRefusals, array_map(static fn (\InvalidArgumentException $refusal): string => $refusal::class, $invalidProfile->refusals));
        }

        self::assertSame('Raj', $user->name());
        self::assertSame('UTC', $user->timeZone());
        self::assertSame(Theme::System, $user->theme());
    }

    #[Test]
    public function changingTheThemeKeepsTheSessionStamp(): void
    {
        $user = self::raj();
        $stampBefore = $user->sessionStamp();

        $user->changeTheme(Theme::Dark);

        self::assertSame(Theme::Dark, $user->theme());
        self::assertSame($stampBefore, $user->sessionStamp());
    }

    private static function raj(): User
    {
        return new User(new UserId(self::ID), new Email('raj@acme-foods.example'), 'Raj', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }
}
