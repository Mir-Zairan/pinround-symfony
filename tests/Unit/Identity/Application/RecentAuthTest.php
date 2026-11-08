<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\RecentAuth;
use App\Identity\Application\SessionConfirmations;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecentAuthTest extends TestCase
{
    private const string LENA_ID = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR_ID = '0197a3c0-0000-7000-8000-0000000000a2';

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
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        self::assertSame($isRecent, RecentAuth::isRecent(self::utc($recentAuthAt), self::utc($twoStepConfirmedAt), $now));
    }

    #[Test]
    public function itReadsTheTimesOfTheUserAskedAbout(): void
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $recentAuth = new RecentAuth(self::confirmationsOf(self::LENA_ID, $now->modify('-5 minutes')));

        self::assertTrue($recentAuth->isRecentFor(new UserId(self::LENA_ID), $now));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::OMAR_ID), $now));
        self::assertFalse($recentAuth->isRecentFor(new UserId(self::LENA_ID), $now->modify('+5 minutes 1 second')));
    }

    private static function utc(?string $time): ?\DateTimeImmutable
    {
        return null === $time ? null : new \DateTimeImmutable($time.' UTC');
    }

    /**
     * Both times recorded for one user, at one moment.
     */
    private static function confirmationsOf(string $userId, \DateTimeImmutable $at): SessionConfirmations
    {
        return new readonly class($userId, $at) implements SessionConfirmations {
            public function __construct(private string $userId, private \DateTimeImmutable $at)
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
                return $userId->toString() === $this->userId ? $this->at : null;
            }

            public function twoStepConfirmedAt(UserId $userId): ?\DateTimeImmutable
            {
                return $userId->toString() === $this->userId ? $this->at : null;
            }

            public function forget(): void
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
