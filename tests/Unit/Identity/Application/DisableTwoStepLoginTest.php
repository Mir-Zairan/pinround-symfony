<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\DisableTwoStepLogin;
use App\Identity\Application\TooManyCurrentPasswordTries;
use App\Identity\Application\TooManyTwoStepCodeTries;
use App\Identity\Application\TwoStepLoginOffOutcome;
use App\Identity\Domain\TwoStepLogin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisableTwoStepLoginTest extends TestCase
{
    use ChecksPasswordAndCode;
    use TwoStepSetupFixtures;

    #[Test]
    public function theRightPasswordAndCodeRemoveItAndForgetTheSessionsProofs(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertSame(TwoStepLoginOffOutcome::TurnedOff, $this->disable($twoStepLogins, self::PASSWORD, self::codeAt(self::SEED, $now), $now));

        self::assertNull($twoStepLogins->byUserId(self::lena()->id()));
        self::assertCount(1, $twoStepLogins->removed);
        self::assertSame(self::lena()->id()->toString(), $twoStepLogins->removed[0]->userId()->toString());
        self::assertSame(['forget'], $this->sessionChanges);
        self::assertSame([1, 1], [$this->passwordTries, $this->codeTries]);
    }

    #[Test]
    public function aRecoveryCodeStandsInForTheAppCode(): void
    {
        $twoStepLogins = $this->turnedOn();

        self::assertSame(TwoStepLoginOffOutcome::TurnedOff, $this->disable($twoStepLogins, self::PASSWORD, strtoupper(self::RECOVERY_CODE), self::confirmedAt()->modify('+5 minutes')));

        self::assertCount(1, $twoStepLogins->removed);
    }

    #[Test]
    public function aWrongPasswordTakesNoCodeTryAndSpendsNoCode(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');
        $code = self::codeAt(self::SEED, $now);

        self::assertSame(TwoStepLoginOffOutcome::WrongPasswordOrCode, $this->disable($twoStepLogins, 'not the password at all', $code, $now));

        self::assertSame([1, 0], [$this->passwordTries, $this->codeTries]);
        self::assertSame(intdiv(self::confirmedAt()->getTimestamp(), 30), $twoStepLogins->byUserId(self::lena()->id())?->lastUsedStep());
        self::assertSame([], $twoStepLogins->removed);
        self::assertSame([], $this->sessionChanges);
        self::assertSame(TwoStepLoginOffOutcome::TurnedOff, $this->disable($twoStepLogins, self::PASSWORD, $code, $now), 'the wrong password spent the code');
    }

    #[Test]
    public function aWrongOrReplayedCodeLeavesItOn(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');

        foreach (['000000', 'q7m2q-x9p4t', self::codeAt(self::SEED, self::confirmedAt())] as $typedCode) {
            self::assertSame(TwoStepLoginOffOutcome::WrongPasswordOrCode, $this->disable($twoStepLogins, self::PASSWORD, $typedCode, $now), $typedCode);
        }

        self::assertTrue($twoStepLogins->byUserId(self::lena()->id())?->isOn());
        self::assertSame([], $twoStepLogins->removed);
        self::assertSame([], $this->sessionChanges);
    }

    #[Test]
    public function whileItIsOffNothingIsCheckedAndNoTryIsTaken(): void
    {
        $awaitingCode = new InMemoryTwoStepLogins();
        $awaitingCode->save(new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), self::confirmedAt()));
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertSame(TwoStepLoginOffOutcome::AlreadyOff, $this->disable($awaitingCode, self::PASSWORD, self::codeAt(self::SEED, $now), $now));
        self::assertSame(TwoStepLoginOffOutcome::AlreadyOff, $this->disable(new InMemoryTwoStepLogins(), self::PASSWORD, self::codeAt(self::SEED, $now), $now));

        self::assertNotNull($awaitingCode->byUserId(self::lena()->id()));
        self::assertSame([], $awaitingCode->removed);
        self::assertSame([0, 0], [$this->passwordTries, $this->codeTries]);
        self::assertSame([], $this->sessionChanges);
        self::assertSame([self::lena()->id()->toString()], $awaitingCode->userIdsReadForChange);
    }

    #[Test]
    public function bothLimitersApply(): void
    {
        $now = self::confirmedAt()->modify('+5 minutes');
        $this->isPasswordThrottled = true;
        $twoStepLogins = $this->turnedOn();
        try {
            $this->disable($twoStepLogins, self::PASSWORD, self::codeAt(self::SEED, $now), $now);
            self::fail('A throttled password was checked.');
        } catch (TooManyCurrentPasswordTries) {
        }
        self::assertSame(0, $this->codeTries);

        $this->isPasswordThrottled = false;
        $this->isCodeThrottled = true;
        try {
            $this->disable($twoStepLogins, self::PASSWORD, self::codeAt(self::SEED, $now), $now);
            self::fail('A throttled code was checked.');
        } catch (TooManyTwoStepCodeTries) {
        }

        self::assertSame([], $twoStepLogins->removed);
        self::assertSame([], $this->sessionChanges);
    }

    private function disable(InMemoryTwoStepLogins $twoStepLogins, string $password, string $typedCode, \DateTimeImmutable $now): TwoStepLoginOffOutcome
    {
        return new DisableTwoStepLogin($twoStepLogins, $this->currentPasswordCheck(), $this->acceptTwoStepCode($twoStepLogins), $this->sessionConfirmations())(self::lena()->id(), $password, $typedCode, $now);
    }
}
