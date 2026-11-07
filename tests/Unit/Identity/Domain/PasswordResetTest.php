<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordResetTest extends TestCase
{
    private const string OLD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';
    private const string NEW_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$4Mw0TAkJ7pBzGS3B1/t1d0I0ww3hCgwdlBBVCZhfKHg';

    #[Test]
    public function aResetExpiresOneHourAfterItWasCreatedAndKeepsOnlyTheTokensHash(): void
    {
        $token = PasswordResetToken::generate();
        $user = self::lena();

        $reset = new PasswordReset('0195a1b2-0000-7000-8000-000000000001', $user, $token, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        self::assertSame('2027-03-15T11:00:00+00:00', $reset->expiresAt()->format(\DATE_ATOM));
        self::assertSame($token->hash(), $reset->tokenHash());
        self::assertTrue($user->id()->equals($reset->userId()));
        self::assertNull($reset->usedAt());
    }

    #[Test]
    public function changingThePasswordReplacesTheHashAndTheChangeTime(): void
    {
        $credential = new Credential(self::lena(), new PasswordHash(self::OLD_HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        $credential->changePassword(new PasswordHash(self::NEW_HASH), new \DateTimeImmutable('2027-03-15 10:30:00 UTC'));

        self::assertSame(self::NEW_HASH, $credential->passwordHash()->encoded());
        self::assertSame('2027-03-15T10:30:00+00:00', $credential->changedAt()->format(\DATE_ATOM));
    }

    private static function lena(): User
    {
        return new User(new UserId('0195a1b2-0000-7000-8000-0000000000aa'), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-01 08:00:00 UTC'));
    }
}
