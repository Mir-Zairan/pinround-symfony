<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ConfirmItsYou;
use App\Identity\Application\ConfirmItsYouOutcome;
use App\Identity\Application\RecentAuth;
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

    #[Test]
    public function itAsksForACodeUnlessTheDemoSwitchIsOnInDev(): void
    {
        $twoStepLogins = new InMemoryTwoStepLogins();

        self::assertTrue($this->confirmItsYou($twoStepLogins)->asksForTwoStepCode());
        self::assertTrue($this->confirmItsYou($twoStepLogins, 'test')->asksForTwoStepCode());
        self::assertTrue($this->confirmItsYou($twoStepLogins, 'prod')->asksForTwoStepCode());
        self::assertFalse($this->confirmItsYou($twoStepLogins, 'dev')->asksForTwoStepCode());
    }

    /**
     * With two-step login off or on: the password alone records the password time and never a two-step time, so no
     * code proof is left behind once the switch is off; the typed code is not looked at and takes no try.
     */
    #[Test]
    public function withTheDemoSwitchOnInDevTheRightPasswordAloneRecordsThePasswordTimeOnly(): void
    {
        $now = self::confirmedAt()->modify('+5 minutes');

        foreach (['off' => new InMemoryTwoStepLogins(), 'on' => $this->turnedOn()] as $state => $twoStepLogins) {
            $this->sessionChanges = [];

            self::assertSame(ConfirmItsYouOutcome::Confirmed, $this->confirmItsYou($twoStepLogins, 'dev')(self::lena()->id(), self::PASSWORD, '', $now), $state);

            self::assertSame(['recent auth at '.$now->getTimestamp()], $this->sessionChanges, $state);
            self::assertSame([], $twoStepLogins->userIdsReadForChange, $state);
        }
        self::assertSame([2, 0], [$this->passwordTries, $this->codeTries]);
    }

    #[Test]
    public function withTheDemoSwitchOnInDevAWrongPasswordRecordsNothing(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertSame(ConfirmItsYouOutcome::WrongPassword, $this->confirmItsYou($twoStepLogins, 'dev')(self::lena()->id(), 'not the password at all', self::codeAt(self::SEED, $now), $now));

        self::assertSame([1, 0], [$this->passwordTries, $this->codeTries]);
        self::assertSame([], $this->sessionChanges);
    }

    private function confirm(InMemoryTwoStepLogins $twoStepLogins, string $password, string $typedCode, \DateTimeImmutable $now): ConfirmItsYouOutcome
    {
        return $this->confirmItsYou($twoStepLogins)(self::lena()->id(), $password, $typedCode, $now);
    }

    /**
     * @param string|null $demoSwitchEnvironment the environment RecentAuth sees with the demo switch on; null for off
     */
    private function confirmItsYou(InMemoryTwoStepLogins $twoStepLogins, ?string $demoSwitchEnvironment = null): ConfirmItsYou
    {
        $sessionConfirmations = $this->sessionConfirmations();
        $recentAuth = new RecentAuth($sessionConfirmations, $twoStepLogins, null !== $demoSwitchEnvironment, $demoSwitchEnvironment ?? 'dev');

        return new ConfirmItsYou($twoStepLogins, $this->currentPasswordCheck(), $this->acceptTwoStepCode($twoStepLogins), $sessionConfirmations, $recentAuth);
    }
}
