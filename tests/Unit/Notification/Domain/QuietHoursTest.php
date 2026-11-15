<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain;

use App\Notification\Domain\QuietHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuietHoursTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function instantsAndTheirQuietEnd(): iterable
    {
        // A winter day in Amsterdam is UTC+1.
        yield '17:59 in Amsterdam is not quiet' => ['Europe/Amsterdam', '2026-02-10T16:59:00Z', null];
        yield '17:59:59 in Amsterdam is not quiet' => ['Europe/Amsterdam', '2026-02-10T16:59:59Z', null];
        yield '18:00 in Amsterdam is quiet until 09:00 the next day' => ['Europe/Amsterdam', '2026-02-10T17:00:00Z', '2026-02-11T09:00:00+01:00'];
        yield '23:30 in Amsterdam is quiet until 09:00 the next day' => ['Europe/Amsterdam', '2026-02-10T22:30:00Z', '2026-02-11T09:00:00+01:00'];
        yield '00:00 in Amsterdam is quiet until 09:00 that day' => ['Europe/Amsterdam', '2026-02-10T23:00:00Z', '2026-02-11T09:00:00+01:00'];
        yield '08:59 in Amsterdam is quiet until 09:00 that day' => ['Europe/Amsterdam', '2026-02-11T07:59:00Z', '2026-02-11T09:00:00+01:00'];
        yield '09:00 in Amsterdam is not quiet' => ['Europe/Amsterdam', '2026-02-11T08:00:00Z', null];
        yield 'noon in Amsterdam is not quiet' => ['Europe/Amsterdam', '2026-02-11T11:00:00Z', null];

        // Kolkata is UTC+5:30: its local clock, not UTC's, decides.
        yield '17:59 in Kolkata is not quiet' => ['Asia/Kolkata', '2026-02-10T12:29:00Z', null];
        yield '18:00 in Kolkata is quiet, at 12:30 UTC' => ['Asia/Kolkata', '2026-02-10T12:30:00Z', '2026-02-11T09:00:00+05:30'];
        yield '03:30 in Kolkata is quiet, at 22:00 UTC the day before' => ['Asia/Kolkata', '2026-02-10T22:00:00Z', '2026-02-11T09:00:00+05:30'];
        yield '09:30 in Kolkata is not quiet, at 04:00 UTC' => ['Asia/Kolkata', '2026-02-11T04:00:00Z', null];

        // Amsterdam moves to summer time (UTC+2) at 02:00 on Sunday 29 March 2026: that night is an hour shorter.
        yield 'the evening before summer time ends at 09:00 summer time' => ['Europe/Amsterdam', '2026-03-28T19:00:00Z', '2026-03-29T09:00:00+02:00'];
        yield '03:30 just after the change ends at 09:00 summer time' => ['Europe/Amsterdam', '2026-03-29T01:30:00Z', '2026-03-29T09:00:00+02:00'];
        yield '09:00 summer time on the change day is not quiet' => ['Europe/Amsterdam', '2026-03-29T07:00:00Z', null];

        // Amsterdam goes back to winter time (UTC+1) at 03:00 on Sunday 25 October 2026: 02:00 to 03:00 comes twice.
        yield 'the evening before winter time ends at 09:00 winter time' => ['Europe/Amsterdam', '2026-10-24T18:00:00Z', '2026-10-25T09:00:00+01:00'];
        yield 'the first 02:30 ends at 09:00 winter time' => ['Europe/Amsterdam', '2026-10-25T00:30:00Z', '2026-10-25T09:00:00+01:00'];
        yield 'the second 02:30 ends at 09:00 winter time' => ['Europe/Amsterdam', '2026-10-25T01:30:00Z', '2026-10-25T09:00:00+01:00'];
        yield '08:59 winter time on the change day is still quiet' => ['Europe/Amsterdam', '2026-10-25T07:59:00Z', '2026-10-25T09:00:00+01:00'];
        yield '09:00 winter time on the change day is not quiet' => ['Europe/Amsterdam', '2026-10-25T08:00:00Z', null];
    }

    #[Test]
    #[DataProvider('instantsAndTheirQuietEnd')]
    public function quietHoursRunFrom18To09InTheRecipientsTimeZone(string $timeZone, string $instant, ?string $expectedEnd): void
    {
        $quietHours = new QuietHours(true, new \DateTimeZone($timeZone));
        $at = new \DateTimeImmutable($instant);

        self::assertSame(null !== $expectedEnd, $quietHours->isQuietAt($at));
        self::assertSame($expectedEnd, $quietHours->endsAt($at)?->format(\DATE_ATOM));
    }

    #[Test]
    public function theEndIsAnInstantWhateverTheZoneOfTheGivenOne(): void
    {
        $quietHours = new QuietHours(true, new \DateTimeZone('Asia/Kolkata'));

        $end = $quietHours->endsAt(new \DateTimeImmutable('2026-02-10T17:30:00-05:00'));

        self::assertNotNull($end);
        self::assertSame('2026-02-11T03:30:00+00:00', $end->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    #[Test]
    public function withTheOrganizationsSwitchOffItIsNeverQuiet(): void
    {
        $quietHours = new QuietHours(false, new \DateTimeZone('Europe/Amsterdam'));

        foreach (['2026-02-10T17:00:00Z', '2026-02-10T23:00:00Z', '2026-02-11T02:00:00Z', '2026-02-11T07:59:00Z'] as $nightInstant) {
            $at = new \DateTimeImmutable($nightInstant);
            self::assertFalse($quietHours->isQuietAt($at), $nightInstant);
            self::assertNull($quietHours->endsAt($at), $nightInstant);
        }
    }
}
