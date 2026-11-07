<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordResetTest extends TestCase
{
    #[Test]
    public function aResetExpiresOneHourAfterItWasCreatedAndKeepsOnlyTheTokensHash(): void
    {
        $token = PasswordResetToken::generate();
        $user = new User(new UserId('0195a1b2-0000-7000-8000-0000000000aa'), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-01 08:00:00 UTC'));

        $reset = new PasswordReset('0195a1b2-0000-7000-8000-000000000001', $user, $token, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        self::assertSame('2027-03-15T11:00:00+00:00', $reset->expiresAt()->format(\DATE_ATOM));
        self::assertSame($token->hash(), $reset->tokenHash());
        self::assertTrue($user->id()->equals($reset->userId()));
        self::assertNull($reset->usedAt());
    }
}
