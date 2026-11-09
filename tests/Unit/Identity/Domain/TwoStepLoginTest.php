<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\TwoStepLoginAlreadyOn;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TwoStepLoginTest extends TestCase
{
    private const array HASHES = ['$argon2id$first', '$argon2id$second'];

    #[Test]
    public function aNewTwoStepLoginIsOffAndHoldsOnlyTheEncryptedSeed(): void
    {
        $user = self::lena();

        $twoStepLogin = new TwoStepLogin($user, 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        self::assertFalse($twoStepLogin->isOn());
        self::assertTrue($user->id()->equals($twoStepLogin->userId()));
        self::assertSame('encrypted seed one', $twoStepLogin->encryptedSeed());
        self::assertNull($twoStepLogin->confirmedAt());
        self::assertSame([], $twoStepLogin->recoveryCodeHashes());
        self::assertNull($twoStepLogin->lastUsedStep());
        self::assertSame('2027-03-15T10:00:00+00:00', $twoStepLogin->createdAt()->format(\DATE_ATOM));
    }

    #[Test]
    public function startingAgainBeforeTheConfirmationReplacesTheSeed(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $twoStepLogin->start('encrypted seed two', new \DateTimeImmutable('2027-03-15 10:05:00 UTC'));

        self::assertSame('encrypted seed two', $twoStepLogin->encryptedSeed());
        self::assertSame('2027-03-15T10:05:00+00:00', $twoStepLogin->createdAt()->format(\DATE_ATOM));
        self::assertFalse($twoStepLogin->isOn());
    }

    #[Test]
    public function confirmingTurnsItOnWithTheRecoveryCodeHashesAndTheCodesStepUsed(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), self::HASHES);

        self::assertTrue($twoStepLogin->isOn());
        self::assertSame('2027-03-15T10:01:00+00:00', $twoStepLogin->confirmedAt()?->format(\DATE_ATOM));
        self::assertSame(self::HASHES, $twoStepLogin->recoveryCodeHashes());
        self::assertSame(58_000_000, $twoStepLogin->lastUsedStep());
        self::assertSame('encrypted seed one', $twoStepLogin->encryptedSeed());
    }

    #[Test]
    public function confirmingTwiceIsRefusedAndKeepsTheFirstCodes(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), self::HASHES);

        try {
            $twoStepLogin->confirm(58_000_010, new \DateTimeImmutable('2027-03-15 10:06:00 UTC'), ['$argon2id$other']);
            self::fail('A second confirmation was taken.');
        } catch (TwoStepLoginAlreadyOn) {
        }

        self::assertSame(self::HASHES, $twoStepLogin->recoveryCodeHashes());
        self::assertSame(58_000_000, $twoStepLogin->lastUsedStep());
        self::assertSame('2027-03-15T10:01:00+00:00', $twoStepLogin->confirmedAt()?->format(\DATE_ATOM));
    }

    #[Test]
    public function startingAgainOnceItIsOnIsRefusedAndKeepsTheSeed(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), self::HASHES);

        try {
            $twoStepLogin->start('encrypted seed two', new \DateTimeImmutable('2027-03-15 10:05:00 UTC'));
            self::fail('A new seed replaced the confirmed one.');
        } catch (TwoStepLoginAlreadyOn) {
        }

        self::assertSame('encrypted seed one', $twoStepLogin->encryptedSeed());
        self::assertTrue($twoStepLogin->isOn());
    }

    #[Test]
    public function confirmingWithoutRecoveryCodesIsRefusedAndLeavesItOff(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        try {
            $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), []);
            self::fail('It turned on without recovery codes.');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse($twoStepLogin->isOn());
        self::assertNull($twoStepLogin->lastUsedStep());
    }

    #[Test]
    public function aCodeCountsOnlyWhenItIsOnAndItsStepIsLaterThanTheLastOneTaken(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        self::assertFalse($twoStepLogin->acceptCode(58_000_001), 'a code counted while it was off');
        self::assertNull($twoStepLogin->lastUsedStep());
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), self::HASHES);

        self::assertFalse($twoStepLogin->acceptCode(58_000_000), 'the confirmation code counted again');
        self::assertFalse($twoStepLogin->acceptCode(57_999_999), 'an older code counted');
        self::assertTrue($twoStepLogin->acceptCode(58_000_001));
        self::assertSame(58_000_001, $twoStepLogin->lastUsedStep());
        self::assertFalse($twoStepLogin->acceptCode(58_000_001), 'the same code counted twice');
    }

    #[Test]
    public function spendingARecoveryCodeRemovesOnlyItsHash(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), ['$argon2id$first', '$argon2id$second', '$argon2id$third']);

        $twoStepLogin->spendRecoveryCode('$argon2id$second');

        self::assertSame(['$argon2id$first', '$argon2id$third'], $twoStepLogin->recoveryCodeHashes());
        self::assertTrue($twoStepLogin->isOn());
        self::assertSame(58_000_000, $twoStepLogin->lastUsedStep());
    }

    #[Test]
    public function spendingAHashItDoesNotHoldIsRefusedAndKeepsEveryHash(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed one', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $twoStepLogin->confirm(58_000_000, new \DateTimeImmutable('2027-03-15 10:01:00 UTC'), self::HASHES);
        $twoStepLogin->spendRecoveryCode('$argon2id$first');

        try {
            $twoStepLogin->spendRecoveryCode('$argon2id$first');
            self::fail('A spent recovery code was spent again.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(['$argon2id$second'], $twoStepLogin->recoveryCodeHashes());
    }

    private static function lena(): User
    {
        return new User(new UserId('0195a1b2-0000-7000-8000-0000000000aa'), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-01 08:00:00 UTC'));
    }
}
