<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\RecentAuth;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\Email;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecentAuthTest extends TestCase
{
    private const string LENA_ID = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR_ID = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string NOW = '2027-03-15 10:00:00';
    /** Lena's two-step login, unless a case says otherwise, was turned on well before any proof below. */
    private const string TURNED_ON_AT = '2027-03-14 08:00:00';

    /**
     * @return iterable<string, array{?string, ?string, bool}> the password time, the code time, whether it is recent
     */
    public static function proofTimes(): iterable
    {
        yield 'both just now' => ['2027-03-15 10:00:00', '2027-03-15 10:00:00', true];
        yield 'both 9 minutes 59 seconds old' => ['2027-03-15 09:50:01', '2027-03-15 09:50:01', true];
        yield 'both exactly 10 minutes old' => ['2027-03-15 09:50:00', '2027-03-15 09:50:00', true];
        yield 'the password 1 second past 10 minutes' => ['2027-03-15 09:49:59', '2027-03-15 09:55:00', false];
        yield 'the code 1 second past 10 minutes' => ['2027-03-15 09:55:00', '2027-03-15 09:49:59', false];
        yield 'the password only, as a login without a code' => ['2027-03-15 09:59:00', null, false];
        yield 'the code only' => [null, '2027-03-15 09:59:00', false];
        yield 'neither' => [null, null, false];
        yield 'both a day old' => ['2027-03-14 10:00:00', '2027-03-14 10:00:00', false];
    }

    #[Test]
    #[DataProvider('proofTimes')]
    public function aProofIsRecentOnlyWhenThePasswordAndTheCodeAreBothAtMostTenMinutesOld(?string $recentAuthAt, ?string $twoStepConfirmedAt, bool $isRecent): void
    {
        $recentAuth = new RecentAuth(self::confirmationsOf(self::LENA_ID, self::utc($recentAuthAt), self::utc($twoStepConfirmedAt)), self::twoStepLoginsOfLena(self::TURNED_ON_AT));

        self::assertSame($isRecent, $recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()));
    }

    #[Test]
    public function itReadsTheTimesOfTheUserAskedAbout(): void
    {
        $fiveMinutesAgo = self::now()->modify('-5 minutes');
        $twoStepLogins = self::twoStepLoginsOfLena(self::TURNED_ON_AT);
        $omar = self::userOf(self::OMAR_ID);
        $omarsTwoStepLogin = new TwoStepLogin($omar, 'encrypted seed', self::utcOf(self::TURNED_ON_AT));
        $omarsTwoStepLogin->confirm(1, self::utcOf(self::TURNED_ON_AT), ['$argon2id$hash']);
        $twoStepLogins->save($omarsTwoStepLogin);
        $recentAuth = new RecentAuth(self::confirmationsOf(self::LENA_ID, $fiveMinutesAgo, $fiveMinutesAgo), $twoStepLogins);

        self::assertTrue($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::OMAR_ID), self::now()));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()->modify('+5 minutes 1 second')));
    }

    /**
     * @return iterable<string, array{int, bool}> the seconds asked for, whether a code of 59 minutes 59 seconds ago counts
     */
    public static function windows(): iterable
    {
        yield 'one hour' => [3600, true];
        yield 'exactly the proof\'s age' => [3599, true];
        yield 'one second less than its age' => [3598, false];
        yield 'ten minutes' => [RecentAuth::WINDOW_SECONDS, false];
    }

    #[Test]
    #[DataProvider('windows')]
    public function aCodeProofCountsWithinTheSecondsAskedFor(int $seconds, bool $counts): void
    {
        $codeAt = self::now()->modify('-3599 seconds');
        $recentAuth = new RecentAuth(self::confirmationsOf(self::LENA_ID, null, $codeAt), self::twoStepLoginsOfLena(self::TURNED_ON_AT));

        self::assertSame($counts, $recentAuth->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), $seconds, self::now()));
    }

    #[Test]
    public function aCodeProofCountsOnlyWhileTwoStepLoginIsOn(): void
    {
        $justNow = self::now();
        $confirmations = self::confirmationsOf(self::LENA_ID, $justNow, $justNow);

        $turnedOff = new RecentAuth($confirmations, new InMemoryTwoStepLogins());
        self::assertFalse($turnedOff->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), 3600, $justNow));
        self::assertFalse($turnedOff->isRecentFor(new UserId(self::LENA_ID), $justNow));

        $awaitingItsFirstCode = new InMemoryTwoStepLogins();
        $awaitingItsFirstCode->save(new TwoStepLogin(self::userOf(self::LENA_ID), 'encrypted seed', self::utcOf(self::TURNED_ON_AT)));
        $setUpAgainButNotOn = new RecentAuth($confirmations, $awaitingItsFirstCode);
        self::assertFalse($setUpAgainButNotOn->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), 3600, $justNow));
        self::assertFalse($setUpAgainButNotOn->isRecentFor(new UserId(self::LENA_ID), $justNow));
    }

    /**
     * Turned off and on again, in another browser, after this session's proof: the proof belongs to the old key.
     */
    #[Test]
    public function aCodeProofFromBeforeTwoStepLoginWasLastTurnedOnDoesNotCount(): void
    {
        $codeAt = self::now()->modify('-5 minutes');
        $confirmations = self::confirmationsOf(self::LENA_ID, $codeAt, $codeAt);

        $turnedOnOneSecondLater = new RecentAuth($confirmations, self::twoStepLoginsOfLena('2027-03-15 09:55:01'));
        self::assertFalse($turnedOnOneSecondLater->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), 3600, self::now()));
        self::assertFalse($turnedOnOneSecondLater->isRecentFor(new UserId(self::LENA_ID), self::now()));

        $turnedOnInTheSameSecond = new RecentAuth($confirmations, self::twoStepLoginsOfLena('2027-03-15 09:55:00.900'));
        self::assertTrue($turnedOnInTheSameSecond->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), 3600, self::now()));
        self::assertTrue($turnedOnInTheSameSecond->isRecentFor(new UserId(self::LENA_ID), self::now()));
    }

    /**
     * Lena has no two-step login and no code in this session, so only the switch can make the proof count.
     */
    #[Test]
    public function theDemoSwitchOnInDevCountsATwoStepProofThatNeverHappened(): void
    {
        $justNow = self::now();
        $passwordOnly = self::confirmationsOf(self::LENA_ID, $justNow, null);

        $recentAuth = new RecentAuth($passwordOnly, new InMemoryTwoStepLogins(), true, 'dev');

        self::assertTrue($recentAuth->isTwoStepLoginSkippedForDemo());
        self::assertTrue($recentAuth->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), 3600, $justNow));
        self::assertTrue($recentAuth->hasConfirmedTwoStepLoginWithin(new UserId(self::OMAR_ID), RecentAuth::WINDOW_SECONDS, $justNow));
        self::assertTrue($recentAuth->isRecentFor(new UserId(self::LENA_ID), $justNow));
    }

    /**
     * The switch skips the code only: the password must still be at most ten minutes old.
     */
    #[Test]
    public function theDemoSwitchStillNeedsARecentPassword(): void
    {
        $passwordOnly = self::confirmationsOf(self::LENA_ID, self::now()->modify('-601 seconds'), null);

        $recentAuth = new RecentAuth($passwordOnly, new InMemoryTwoStepLogins(), true, 'dev');

        self::assertFalse($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::OMAR_ID), self::now()));
        self::assertTrue($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()->modify('-1 second')));
    }

    /**
     * @return iterable<string, array{bool, string}> the switch, the kernel's environment
     */
    public static function switchesThatSkipNothing(): iterable
    {
        yield 'on in prod' => [true, 'prod'];
        yield 'on in test' => [true, 'test'];
        yield 'on in an environment named Dev' => [true, 'Dev'];
        yield 'on with no environment' => [true, ''];
        yield 'off in dev' => [false, 'dev'];
    }

    #[Test]
    #[DataProvider('switchesThatSkipNothing')]
    public function theDemoSwitchSkipsNothingUnlessItIsOnInDev(bool $isDemoSwitchOn, string $appEnvironment): void
    {
        $justNow = self::now();
        $passwordOnly = self::confirmationsOf(self::LENA_ID, $justNow, null);

        $recentAuth = new RecentAuth($passwordOnly, new InMemoryTwoStepLogins(), $isDemoSwitchOn, $appEnvironment);

        self::assertFalse($recentAuth->isTwoStepLoginSkippedForDemo());
        self::assertFalse($recentAuth->hasConfirmedTwoStepLoginWithin(new UserId(self::LENA_ID), 3600, $justNow));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::LENA_ID), $justNow));
    }

    /**
     * Off, the proof is judged exactly as without the switch: the same code counts and the same old code does not.
     */
    #[Test]
    public function theDemoSwitchOffKeepsTheRealProof(): void
    {
        $codeAt = self::now()->modify('-5 minutes');
        $recentAuth = new RecentAuth(self::confirmationsOf(self::LENA_ID, $codeAt, $codeAt), self::twoStepLoginsOfLena(self::TURNED_ON_AT), false, 'dev');

        self::assertTrue($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()->modify('+5 minutes 1 second')));
        self::assertFalse($recentAuth->hasConfirmedTwoStepLoginWithin(new UserId(self::OMAR_ID), 3600, self::now()));
    }

    #[Test]
    public function withoutTheDemoArgumentsTheSwitchIsOff(): void
    {
        $recentAuth = new RecentAuth(self::confirmationsOf(self::LENA_ID, self::now(), null), new InMemoryTwoStepLogins());

        self::assertFalse($recentAuth->isTwoStepLoginSkippedForDemo());
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::LENA_ID), self::now()));
    }

    private static function now(): \DateTimeImmutable
    {
        return self::utcOf(self::NOW);
    }

    private static function utc(?string $time): ?\DateTimeImmutable
    {
        return null === $time ? null : self::utcOf($time);
    }

    private static function utcOf(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time.' UTC');
    }

    private static function userOf(string $userId): User
    {
        return new User(new UserId($userId), new Email('person-'.substr($userId, -2).'@acme-foods.example'), 'A person', 'en', 'Europe/Berlin', self::utcOf('2027-03-01 09:00:00'));
    }

    /**
     * Lena's two-step login, turned on at $turnedOnAt (UTC).
     */
    private static function twoStepLoginsOfLena(string $turnedOnAt): InMemoryTwoStepLogins
    {
        $twoStepLogin = new TwoStepLogin(self::userOf(self::LENA_ID), 'encrypted seed', self::utcOf($turnedOnAt));
        $twoStepLogin->confirm(1, self::utcOf($turnedOnAt), ['$argon2id$hash']);
        $twoStepLogins = new InMemoryTwoStepLogins();
        $twoStepLogins->save($twoStepLogin);

        return $twoStepLogins;
    }

    /**
     * The two times recorded for one user, as the session stores them: to the second.
     */
    private static function confirmationsOf(string $userId, ?\DateTimeImmutable $recentAuthAt, ?\DateTimeImmutable $twoStepConfirmedAt): SessionConfirmations
    {
        return new readonly class($userId, $recentAuthAt, $twoStepConfirmedAt) implements SessionConfirmations {
            public function __construct(private string $userId, private ?\DateTimeImmutable $recentAuthAt, private ?\DateTimeImmutable $twoStepConfirmedAt)
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
                return $userId->toString() === $this->userId ? $this->recentAuthAt : null;
            }

            public function twoStepConfirmedAt(UserId $userId): ?\DateTimeImmutable
            {
                return $userId->toString() === $this->userId ? $this->twoStepConfirmedAt : null;
            }

            public function forget(): void
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
