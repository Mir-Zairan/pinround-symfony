<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\AcceptTwoStepCode;
use App\Identity\Application\CurrentPasswordCheck;
use App\Identity\Application\CurrentPasswordLimiter;
use App\Identity\Application\DisableTwoStepLogin;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Application\TooManyCurrentPasswordTries;
use App\Identity\Application\TooManyTwoStepCodeTries;
use App\Identity\Application\TwoStepCodeLimiter;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\TwoStepLogin;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisableTwoStepLoginTest extends TestCase
{
    use TwoStepSetupFixtures;

    private const string SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const string PASSWORD = 'correct horse battery staple';

    private int $passwordTries = 0;
    private int $codeTries = 0;
    private bool $isPasswordThrottled = false;
    private bool $isCodeThrottled = false;
    private int $forgetCalls = 0;

    #[Test]
    public function theRightPasswordAndCodeRemoveItAndForgetTheSessionsProofs(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertTrue($this->disable($twoStepLogins, self::PASSWORD, self::codeAt(self::SEED, $now), $now));

        self::assertNull($twoStepLogins->byUserId(self::lena()->id()));
        self::assertCount(1, $twoStepLogins->removed);
        self::assertSame(self::lena()->id()->toString(), $twoStepLogins->removed[0]->userId()->toString());
        self::assertSame(1, $this->forgetCalls);
        self::assertSame([1, 1], [$this->passwordTries, $this->codeTries]);
    }

    #[Test]
    public function aRecoveryCodeStandsInForTheAppCode(): void
    {
        $twoStepLogins = $this->turnedOn();

        self::assertTrue($this->disable($twoStepLogins, self::PASSWORD, 'K7M2Q-X9P4T', self::confirmedAt()->modify('+5 minutes')));

        self::assertCount(1, $twoStepLogins->removed);
    }

    #[Test]
    public function aWrongPasswordTakesNoCodeTryAndSpendsNoCode(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');
        $code = self::codeAt(self::SEED, $now);

        self::assertFalse($this->disable($twoStepLogins, 'not the password at all', $code, $now));

        self::assertSame([1, 0], [$this->passwordTries, $this->codeTries]);
        self::assertSame(intdiv(self::confirmedAt()->getTimestamp(), 30), $twoStepLogins->byUserId(self::lena()->id())?->lastUsedStep());
        self::assertSame([], $twoStepLogins->removed);
        self::assertSame(0, $this->forgetCalls);
        self::assertTrue($this->disable($twoStepLogins, self::PASSWORD, $code, $now), 'the wrong password spent the code');
    }

    #[Test]
    public function aWrongOrReplayedCodeLeavesItOn(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+5 minutes');

        foreach (['000000', 'q7m2q-x9p4t', self::codeAt(self::SEED, self::confirmedAt())] as $typedCode) {
            self::assertFalse($this->disable($twoStepLogins, self::PASSWORD, $typedCode, $now), $typedCode);
        }

        self::assertTrue($twoStepLogins->byUserId(self::lena()->id())?->isOn());
        self::assertSame([], $twoStepLogins->removed);
        self::assertSame(0, $this->forgetCalls);
    }

    #[Test]
    public function whileItIsOffNothingIsCheckedAndNoTryIsTaken(): void
    {
        $awaitingCode = new InMemoryTwoStepLogins();
        $awaitingCode->save(new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), self::confirmedAt()));
        $now = self::confirmedAt()->modify('+5 minutes');

        self::assertFalse($this->disable($awaitingCode, self::PASSWORD, self::codeAt(self::SEED, $now), $now));
        self::assertFalse($this->disable(new InMemoryTwoStepLogins(), self::PASSWORD, self::codeAt(self::SEED, $now), $now));

        self::assertNotNull($awaitingCode->byUserId(self::lena()->id()));
        self::assertSame([], $awaitingCode->removed);
        self::assertSame([0, 0, 0], [$this->passwordTries, $this->codeTries, $this->forgetCalls]);
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
        self::assertSame(0, $this->forgetCalls);
    }

    private function turnedOn(): InMemoryTwoStepLogins
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 09:59:00 UTC'));
        $twoStepLogin->confirm(intdiv(self::confirmedAt()->getTimestamp(), 30), self::confirmedAt(), [self::readableHashing()->hash('k7m2q-x9p4t')]);
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save($twoStepLogin);

        return $twoStepLogins;
    }

    private static function confirmedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    private function disable(InMemoryTwoStepLogins $twoStepLogins, string $password, string $typedCode, \DateTimeImmutable $now): bool
    {
        $credential = new Credential(self::lena(), new PasswordHash('$plain$'.self::PASSWORD), new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $credentials = new readonly class($credential) implements Credentials {
            public function __construct(private Credential $credential)
            {
            }

            public function byUserId(UserId $userId): Credential
            {
                return $this->credential;
            }

            public function add(Credential $credential): void
            {
                throw new \LogicException('Not used.');
            }

            public function save(Credential $credential): void
            {
                throw new \LogicException('Not used.');
            }
        };
        $passwordHashing = new class implements PasswordHashing {
            public function hash(NewPassword $password): PasswordHash
            {
                throw new \LogicException('Not used.');
            }

            public function matches(PasswordHash $hash, #[\SensitiveParameter] string $typedPassword): bool
            {
                return $hash->encoded() === '$plain$'.$typedPassword;
            }
        };
        $passwordLimiter = new readonly class($this->takePasswordTry(...)) implements CurrentPasswordLimiter {
            /**
             * @param \Closure(): void $takeTry
             */
            public function __construct(private \Closure $takeTry)
            {
            }

            public function consume(UserId $userId): void
            {
                ($this->takeTry)();
            }
        };
        $codeLimiter = new readonly class($this->takeCodeTry(...)) implements TwoStepCodeLimiter {
            /**
             * @param \Closure(): void $takeTry
             */
            public function __construct(private \Closure $takeTry)
            {
            }

            public function consume(UserId $userId): void
            {
                ($this->takeTry)();
            }
        };
        $sessionConfirmations = new readonly class($this->recordForget(...)) implements SessionConfirmations {
            /**
             * @param \Closure(): void $recordForget
             */
            public function __construct(private \Closure $recordForget)
            {
            }

            public function recordRecentAuth(UserId $userId, \DateTimeImmutable $now): void
            {
                throw new \LogicException('Not used.');
            }

            public function recordTwoStepConfirmation(UserId $userId, \DateTimeImmutable $now): void
            {
                throw new \LogicException('Not used.');
            }

            public function recentAuthAt(UserId $userId): ?\DateTimeImmutable
            {
                throw new \LogicException('Not used.');
            }

            public function twoStepConfirmedAt(UserId $userId): ?\DateTimeImmutable
            {
                throw new \LogicException('Not used.');
            }

            public function forget(): void
            {
                ($this->recordForget)();
            }
        };

        return new DisableTwoStepLogin(
            $twoStepLogins,
            new CurrentPasswordCheck($credentials, $passwordHashing, $passwordLimiter),
            new AcceptTwoStepCode($twoStepLogins, self::fieldEncryption(), self::oneTimeCodes(), self::readableHashing(), $codeLimiter),
            $sessionConfirmations,
        )(self::lena()->id(), $password, $typedCode, $now);
    }

    private function takePasswordTry(): void
    {
        if ($this->isPasswordThrottled) {
            throw new TooManyCurrentPasswordTries(15);
        }
        ++$this->passwordTries;
    }

    private function takeCodeTry(): void
    {
        if ($this->isCodeThrottled) {
            throw new TooManyTwoStepCodeTries(15);
        }
        ++$this->codeTries;
    }

    private function recordForget(): void
    {
        ++$this->forgetCalls;
    }
}
