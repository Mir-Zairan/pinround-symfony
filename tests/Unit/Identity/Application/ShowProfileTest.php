<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Profile;
use App\Identity\Application\ShowProfile;
use App\Identity\Domain\Email;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShowProfileTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-0000000000c1';

    #[Test]
    public function itShowsTheSavedNameTimeZoneAndTheme(): void
    {
        $user = new User(new UserId(self::ID), new Email('mia@acme-foods.example'), 'Mia Lund', 'en', 'US/Pacific', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $user->changeTheme(Theme::Light);

        self::assertEquals(new Profile('Mia Lund', 'US/Pacific', 'light'), new ShowProfile(new RecordingUsers($user))(new UserId(self::ID)));
    }

    #[Test]
    public function anUnknownUserIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        new ShowProfile(new RecordingUsers(null))(new UserId(self::ID));
    }
}
