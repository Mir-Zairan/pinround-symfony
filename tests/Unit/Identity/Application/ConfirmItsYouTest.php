<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ConfirmItsYou;
use App\Identity\Application\ConfirmItsYouOutcome;
use App\Identity\Domain\TwoStepLogin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfirmItsYouTest extends TestCase
{
    use ChecksPasswordAndCode;
    use TwoStepSetupFixtures;

    #[Test]
    public function theRightPasswordAndCodeRecordBothTimes(): void
    {
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertSame(ConfirmItsYouOutcome::Confirmed, $this->confirm($this->turnedOn(), self::PASSWORD, self::codeAt(self::SEED, $now), $now));

        self::assertSame(['recent auth at '.$now->getTimestamp(), 'two-step confirmed at '.$now->getTimestamp()], $this->sessionChanges);
    }

    #[Test]
    public function aWrongPasswordOrCodeRecordsNothing(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertSame(ConfirmItsYouOutcome::WrongPasswordOrCode, $this->confirm($twoStepLogins, 'not the password at all', self::codeAt(self::SEED, $now), $now));
        self::assertSame(ConfirmItsYouOutcome::WrongPasswordOrCode, $this->confirm($twoStepLogins, self::PASSWORD, '000000', $now));

        self::assertSame([2, 1], [$this->passwordTries, $this->codeTries]);
        self::assertSame([], $this->sessionChanges);
    }

    /**
     * Another request of the person turned it off after the page checked its state: no code to ask for, so no try.
     */
    #[Test]
    public function whileItIsOffNothingIsCheckedAndNoTryIsTaken(): void
    {
        $awaitingCode = new InMemoryTwoStepLogins();
        $awaitingCode->save(new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), self::confirmedAt()));
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertSame(ConfirmItsYouOutcome::TwoStepLoginOff, $this->confirm($awaitingCode, self::PASSWORD, self::codeAt(self::SEED, $now), $now));
        self::assertSame(ConfirmItsYouOutcome::TwoStepLoginOff, $this->confirm(new InMemoryTwoStepLogins(), self::PASSWORD, self::RECOVERY_CODE, $now));

        self::assertSame([0, 0], [$this->passwordTries, $this->codeTries]);
        self::assertSame([], $this->sessionChanges);
        self::assertSame([self::lena()->id()->toString()], $awaitingCode->userIdsReadForChange);
    }

    private function confirm(InMemoryTwoStepLogins $twoStepLogins, string $password, string $typedCode, \DateTimeImmutable $now): ConfirmItsYouOutcome
    {
        return new ConfirmItsYou($twoStepLogins, $this->currentPasswordCheck(), $this->acceptTwoStepCode($twoStepLogins), $this->sessionConfirmations())(self::lena()->id(), $password, $typedCode, $now);
    }
}
