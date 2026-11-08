<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\AcceptTwoStepCode;
use App\Identity\Application\CurrentPasswordCheck;
use App\Identity\Application\CurrentPasswordLimiter;
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

/**
 * The password and code checks of the commands that ask for both, for Lena of TwoStepSetupFixtures, counting the tries
 * of both limiters and what the session records. Use it next to TwoStepSetupFixtures.
 */
trait ChecksPasswordAndCode
{
    private const string SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const string PASSWORD = 'correct horse battery staple';
    private const string RECOVERY_CODE = 'k7m2q-x9p4t';

    private int $passwordTries = 0;
    private int $codeTries = 0;
    private bool $isPasswordThrottled = false;
    private bool $isCodeThrottled = false;
    /** @var list<string> "recent auth at <unix seconds>", "two-step confirmed at <unix seconds>" or "forget" */
    private array $sessionChanges = [];

    /**
     * Lena's two-step login, confirmed at confirmedAt() with RECOVERY_CODE as its only recovery code.
     */
    private function turnedOn(): InMemoryTwoStepLogins
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 09:59:00 UTC'));
        $twoStepLogin->confirm(intdiv(self::confirmedAt()->getTimestamp(), 30), self::confirmedAt(), [self::readableHashing()->hash(self::RECOVERY_CODE)]);
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save($twoStepLogin);

        return $twoStepLogins;
    }

    private static function confirmedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    private function currentPasswordCheck(): CurrentPasswordCheck
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
        $limiter = new readonly class($this->takePasswordTry(...)) implements CurrentPasswordLimiter {
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

        return new CurrentPasswordCheck($credentials, $passwordHashing, $limiter);
    }

    private function acceptTwoStepCode(InMemoryTwoStepLogins $twoStepLogins): AcceptTwoStepCode
    {
        $limiter = new readonly class($this->takeCodeTry(...)) implements TwoStepCodeLimiter {
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

        return new AcceptTwoStepCode($twoStepLogins, self::fieldEncryption(), self::oneTimeCodes(), self::readableHashing(), $limiter);
    }

    private function sessionConfirmations(): SessionConfirmations
    {
        return new readonly class($this->recordSessionChange(...)) implements SessionConfirmations {
            /**
             * @param \Closure(string): void $record
             */
            public function __construct(private \Closure $record)
            {
            }

            public function recordRecentAuth(UserId $userId, \DateTimeImmutable $now): void
            {
                ($this->record)('recent auth at '.$now->getTimestamp());
            }

            public function recordTwoStepConfirmation(UserId $userId, \DateTimeImmutable $now): void
            {
                ($this->record)('two-step confirmed at '.$now->getTimestamp());
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
                ($this->record)('forget');
            }
        };
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

    private function recordSessionChange(string $change): void
    {
        $this->sessionChanges[] = $change;
    }
}
