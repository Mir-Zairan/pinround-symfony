<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ConfirmTwoStepSetup;
use App\Identity\Application\RecoveryCodes;
use App\Identity\Application\TwoStepConfirmRefusal;
use App\Identity\Domain\TwoStepLogin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfirmTwoStepSetupTest extends TestCase
{
    use TwoStepSetupFixtures;

    private const string SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    #[Test]
    public function theRightCodeTurnsItOnAndReturnsTenCodesWhoseHashesAreStored(): void
    {
        $twoStepLogins = $this->awaitingCode();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');

        $outcome = $this->confirm($twoStepLogins, self::codeAt(self::SEED, $now), $now);

        self::assertInstanceOf(RecoveryCodes::class, $outcome);
        $twoStepLogin = $twoStepLogins->byUserId(self::lena()->id());
        self::assertTrue($twoStepLogin?->isOn());
        self::assertSame('2027-03-15T10:01:15+00:00', $twoStepLogin->confirmedAt()?->format(\DATE_ATOM));
        self::assertCount(10, $outcome->reveal());
        self::assertSame(array_map(static fn (string $code): string => 'hash of '.$code, $outcome->reveal()), $twoStepLogin->recoveryCodeHashes());
        self::assertSame(intdiv($now->getTimestamp(), 30), $twoStepLogin->lastUsedStep());
        self::assertSame([self::lena()->id()->toString()], $twoStepLogins->userIdsReadForChange);
    }

    #[Test]
    public function spacesInTheTypedCodeAreIgnored(): void
    {
        $twoStepLogins = $this->awaitingCode();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');
        $code = self::codeAt(self::SEED, $now);

        $outcome = $this->confirm($twoStepLogins, ' '.substr($code, 0, 3).' '.substr($code, 3).' ', $now);

        self::assertInstanceOf(RecoveryCodes::class, $outcome);
        self::assertTrue($twoStepLogins->byUserId(self::lena()->id())?->isOn());
    }

    #[Test]
    public function aWrongCodeLeavesItOffAndSavesNothing(): void
    {
        $twoStepLogins = $this->awaitingCode();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');
        // The code of two minutes earlier: right seed, outside the window of one step either side.
        $staleCode = self::codeAt(self::SEED, $now->modify('-2 minutes'));
        self::assertNotSame($staleCode, self::codeAt(self::SEED, $now));

        foreach ([$staleCode, '12345', '12345a', 'abcdef', ''] as $typedCode) {
            self::assertSame(TwoStepConfirmRefusal::WrongCode, $this->confirm($twoStepLogins, $typedCode, $now), $typedCode);
        }

        $twoStepLogin = $twoStepLogins->byUserId(self::lena()->id());
        self::assertFalse($twoStepLogin?->isOn());
        self::assertSame([], $twoStepLogin->recoveryCodeHashes());
        self::assertNull($twoStepLogin->lastUsedStep());
        self::assertCount(1, $twoStepLogins->saved);
    }

    #[Test]
    public function aUserWhoHasNotTurnedItOnHasNothingToConfirm(): void
    {
        $twoStepLogins = new InMemoryTwoStepLogins();

        self::assertSame(TwoStepConfirmRefusal::NotStarted, $this->confirm($twoStepLogins, '123456', new \DateTimeImmutable('2027-03-15 10:01:15 UTC')));
        self::assertSame([], $twoStepLogins->saved);
    }

    #[Test]
    public function confirmingAgainOnceItIsOnKeepsTheFirstRecoveryCodes(): void
    {
        $twoStepLogins = $this->awaitingCode();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');
        $this->confirm($twoStepLogins, self::codeAt(self::SEED, $now), $now);
        $hashesBefore = $twoStepLogins->byUserId(self::lena()->id())?->recoveryCodeHashes();
        $later = $now->modify('+1 minute');

        $outcome = $this->confirm($twoStepLogins, self::codeAt(self::SEED, $later), $later);

        self::assertSame(TwoStepConfirmRefusal::AlreadyOn, $outcome);
        self::assertSame($hashesBefore, $twoStepLogins->byUserId(self::lena()->id())?->recoveryCodeHashes());
        self::assertCount(2, $twoStepLogins->saved);
    }

    private function awaitingCode(): InMemoryTwoStepLogins
    {
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save(new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        return $twoStepLogins;
    }

    private function confirm(InMemoryTwoStepLogins $twoStepLogins, string $typedCode, \DateTimeImmutable $now): RecoveryCodes|TwoStepConfirmRefusal
    {
        return new ConfirmTwoStepSetup($twoStepLogins, self::fieldEncryption(), self::oneTimeCodes(), self::readableHashing())(self::lena()->id(), $typedCode, $now);
    }
}
