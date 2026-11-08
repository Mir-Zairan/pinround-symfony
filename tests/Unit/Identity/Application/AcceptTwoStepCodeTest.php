<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\AcceptTwoStepCode;
use App\Identity\Application\TooManyTwoStepCodeTries;
use App\Identity\Application\TwoStepCodeLimiter;
use App\Identity\Domain\RecoveryCodeHashing;
use App\Identity\Domain\TwoStepLogin;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptTwoStepCodeTest extends TestCase
{
    use TwoStepSetupFixtures;

    private const string SEED = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const array RECOVERY_CODES = ['k7m2q-x9p4t', 'a1b2c-d3e4f', 'zz9yy-8xx7w'];

    private int $tries = 0;
    private ?int $refuseAfterTries = null;
    /** @var list<string> */
    private array $checkedRecoveryCodes = [];

    #[Test]
    public function theRightAuthenticatorCodeIsAcceptedAndItsStepCountsAsUsed(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');

        self::assertTrue($this->accept($twoStepLogins, self::codeAt(self::SEED, $now), $now));

        $twoStepLogin = $twoStepLogins->byUserId(self::lena()->id());
        self::assertSame(intdiv($now->getTimestamp(), 30), $twoStepLogin?->lastUsedStep());
        self::assertSame(self::hashesOf(self::RECOVERY_CODES), $twoStepLogin->recoveryCodeHashes());
        self::assertCount(2, $twoStepLogins->saved);
        self::assertSame([self::lena()->id()->toString()], $twoStepLogins->userIdsReadForChange);
        self::assertSame([], $this->checkedRecoveryCodes);
    }

    #[Test]
    public function spacesInAnAuthenticatorCodeAreIgnored(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');
        $code = self::codeAt(self::SEED, $now);

        self::assertTrue($this->accept($twoStepLogins, ' '.substr($code, 0, 3).' '.substr($code, 3).' ', $now));
    }

    #[Test]
    public function theSameAuthenticatorCodeIsRefusedTheSecondTime(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');
        $code = self::codeAt(self::SEED, $now);
        self::assertTrue($this->accept($twoStepLogins, $code, $now));

        self::assertFalse($this->accept($twoStepLogins, $code, $now->modify('+10 seconds')));

        self::assertSame(intdiv($now->getTimestamp(), 30), $twoStepLogins->byUserId(self::lena()->id())?->lastUsedStep());
        self::assertCount(2, $twoStepLogins->saved);
    }

    #[Test]
    public function aWrongCodeIsRefusedAndNothingIsSpent(): void
    {
        $twoStepLogins = $this->turnedOn();
        // A code of two minutes after the confirmation, so its step is later than the one used and replay cannot be
        // what refuses it; typed eight minutes later, it is outside the window of one step either side.
        $staleCodeAt = self::confirmedAt()->modify('+2 minutes');
        $staleCode = self::codeAt(self::SEED, $staleCodeAt);
        $now = self::confirmedAt()->modify('+10 minutes');
        self::assertNotSame($staleCode, self::codeAt(self::SEED, $now));

        foreach ([$staleCode, '12345', '1234567', 'abcdef', '', 'q7m2q-x9p4t', 'k7m2q-x9p4'] as $typedCode) {
            self::assertFalse($this->accept($twoStepLogins, $typedCode, $now), $typedCode);
        }

        $twoStepLogin = $twoStepLogins->byUserId(self::lena()->id());
        self::assertSame(intdiv(self::confirmedAt()->getTimestamp(), 30), $twoStepLogin?->lastUsedStep());
        self::assertSame(self::hashesOf(self::RECOVERY_CODES), $twoStepLogin->recoveryCodeHashes());
        self::assertCount(1, $twoStepLogins->saved);
        self::assertTrue($this->accept($twoStepLogins, $staleCode, $staleCodeAt), 'the stale code was not a code of the seed');
    }

    #[Test]
    public function onlyInputInTheShapeOfARecoveryCodeIsCheckedAgainstTheHashes(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = self::confirmedAt()->modify('+10 minutes');

        foreach ([self::codeAt(self::SEED, self::confirmedAt()->modify('+2 minutes')), '12345', 'abcdef', 'k7m2q-x9p4', 'k7m2q-x9p4t1', 'k7m2q_x9p4t', ''] as $typedCode) {
            $this->accept($twoStepLogins, $typedCode, $now);
        }
        self::assertSame([], $this->checkedRecoveryCodes);

        $this->accept($twoStepLogins, 'q7m2q-x9p4t', $now);
        self::assertSame(['q7m2q-x9p4t', 'q7m2q-x9p4t', 'q7m2q-x9p4t'], $this->checkedRecoveryCodes);
    }

    #[Test]
    public function aRecoveryCodeIsAcceptedOnceAndThenRefused(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');

        self::assertTrue($this->accept($twoStepLogins, 'a1b2c-d3e4f', $now));

        self::assertSame(self::hashesOf(['k7m2q-x9p4t', 'zz9yy-8xx7w']), $twoStepLogins->byUserId(self::lena()->id())?->recoveryCodeHashes());
        $savesAfterTheAcceptance = \count($twoStepLogins->saved);
        self::assertSame(2, $savesAfterTheAcceptance);

        self::assertFalse($this->accept($twoStepLogins, 'a1b2c-d3e4f', $now->modify('+1 minute')));
        self::assertSame(self::hashesOf(['k7m2q-x9p4t', 'zz9yy-8xx7w']), $twoStepLogins->byUserId(self::lena()->id())?->recoveryCodeHashes());
        self::assertCount(2, $twoStepLogins->saved);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typedFormsOfARecoveryCode(): iterable
    {
        yield 'upper case' => ['A1B2C-D3E4F'];
        yield 'without the dash' => ['a1b2cd3e4f'];
        yield 'upper case without the dash' => ['A1B2CD3E4F'];
        yield 'a space for the dash' => ['a1b2c d3e4f'];
        yield 'spaces around it' => [' a1b2c-d3e4f '];
        yield 'mixed case' => ['a1B2c-D3e4F'];
    }

    #[Test]
    #[DataProvider('typedFormsOfARecoveryCode')]
    public function aRecoveryCodeIsAcceptedInAnyCaseWithOrWithoutTheDash(string $typedCode): void
    {
        $twoStepLogins = $this->turnedOn();

        self::assertTrue($this->accept($twoStepLogins, $typedCode, new \DateTimeImmutable('2027-03-15 10:01:15 UTC')));

        self::assertSame(self::hashesOf(['k7m2q-x9p4t', 'zz9yy-8xx7w']), $twoStepLogins->byUserId(self::lena()->id())?->recoveryCodeHashes());
    }

    #[Test]
    public function whileItIsOffEveryCodeIsRefused(): void
    {
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save(new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');

        self::assertFalse($this->accept($twoStepLogins, self::codeAt(self::SEED, $now), $now));
        self::assertFalse($this->accept($twoStepLogins, 'k7m2q-x9p4t', $now));

        self::assertNull($twoStepLogins->byUserId(self::lena()->id())?->lastUsedStep());
        self::assertCount(1, $twoStepLogins->saved);
    }

    #[Test]
    public function aUserWhoNeverSetItUpHasEveryCodeRefused(): void
    {
        $twoStepLogins = new InMemoryTwoStepLogins();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');

        self::assertFalse($this->accept($twoStepLogins, self::codeAt(self::SEED, $now), $now));
        self::assertFalse($this->accept($twoStepLogins, 'k7m2q-x9p4t', $now));
        self::assertSame([], $twoStepLogins->saved);
    }

    #[Test]
    public function everyTryCountsAgainstTheLimiterRightWrongOrOff(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');

        $this->accept($twoStepLogins, self::codeAt(self::SEED, $now), $now);
        $this->accept($twoStepLogins, '000000', $now);
        $this->accept($twoStepLogins, 'k7m2q-x9p4t', $now);
        $this->accept($twoStepLogins, 'not a code', $now);
        $this->accept(new InMemoryTwoStepLogins(), '123456', $now);

        self::assertSame(5, $this->tries);
    }

    #[Test]
    public function oncePastTheLimitEvenTheRightCodeIsRefusedBeforeAnythingIsRead(): void
    {
        $twoStepLogins = $this->turnedOn();
        $now = new \DateTimeImmutable('2027-03-15 10:01:15 UTC');
        $this->refuseAfterTries = 0;

        foreach ([self::codeAt(self::SEED, $now), 'k7m2q-x9p4t'] as $typedCode) {
            try {
                $this->accept($twoStepLogins, $typedCode, $now);
                self::fail('A try past the limit was checked.');
            } catch (TooManyTwoStepCodeTries $refusal) {
                self::assertSame(15, $refusal->minutesToWait);
            }
        }

        self::assertSame([], $twoStepLogins->userIdsReadForChange);
        self::assertSame([], $this->checkedRecoveryCodes);
        $twoStepLogin = $twoStepLogins->byUserId(self::lena()->id());
        self::assertSame(intdiv(self::confirmedAt()->getTimestamp(), 30), $twoStepLogin?->lastUsedStep());
        self::assertSame(self::hashesOf(self::RECOVERY_CODES), $twoStepLogin->recoveryCodeHashes());
    }

    private function turnedOn(): InMemoryTwoStepLogins
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), self::fieldEncryption()->encrypt(self::SEED), new \DateTimeImmutable('2027-03-15 09:59:00 UTC'));
        $twoStepLogin->confirm(intdiv(self::confirmedAt()->getTimestamp(), 30), self::confirmedAt(), self::hashesOf(self::RECOVERY_CODES));
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save($twoStepLogin);

        return $twoStepLogins;
    }

    private static function confirmedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
    }

    /**
     * @param list<string> $recoveryCodes
     *
     * @return list<string>
     */
    private static function hashesOf(array $recoveryCodes): array
    {
        return array_map(self::readableHashing()->hash(...), $recoveryCodes);
    }

    private function accept(InMemoryTwoStepLogins $twoStepLogins, string $typedCode, \DateTimeImmutable $now): bool
    {
        $limiter = new readonly class($this->takeTry(...)) implements TwoStepCodeLimiter {
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
        $hashing = new readonly class(self::readableHashing(), $this->recordRecoveryCodeCheck(...)) implements RecoveryCodeHashing {
            /**
             * @param \Closure(string): void $recordCheck
             */
            public function __construct(private RecoveryCodeHashing $hashing, private \Closure $recordCheck)
            {
            }

            public function hash(#[\SensitiveParameter] string $recoveryCode): string
            {
                return $this->hashing->hash($recoveryCode);
            }

            public function matches(string $hash, #[\SensitiveParameter] string $typedRecoveryCode): bool
            {
                ($this->recordCheck)($typedRecoveryCode);

                return $this->hashing->matches($hash, $typedRecoveryCode);
            }
        };

        return new AcceptTwoStepCode($twoStepLogins, self::fieldEncryption(), self::oneTimeCodes(), $hashing, $limiter)(self::lena()->id(), $typedCode, $now);
    }

    private function takeTry(): void
    {
        if (null !== $this->refuseAfterTries && $this->tries >= $this->refuseAfterTries) {
            throw new TooManyTwoStepCodeTries(15);
        }
        ++$this->tries;
    }

    private function recordRecoveryCodeCheck(string $typedRecoveryCode): void
    {
        $this->checkedRecoveryCodes[] = $typedRecoveryCode;
    }
}
