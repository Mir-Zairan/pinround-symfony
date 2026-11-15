<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * The organization's quiet hours rule for one recipient: while it is on, it is quiet from 18:00 until 09:00 on the
 * recipient's local clock, and a ping is held until 09:00.
 */
final readonly class QuietHours
{
    public const int STARTS_AT_HOUR = 18;
    public const int ENDS_AT_HOUR = 9;

    /**
     * @param bool          $isOn              the organization's quiet hours switch
     * @param \DateTimeZone $recipientTimeZone the recipient's IANA time zone
     */
    public function __construct(
        private bool $isOn,
        private \DateTimeZone $recipientTimeZone,
    ) {
    }

    /**
     * Whether it is quiet at the instant, from 18:00:00 up to but not including 09:00:00 local time. Never when off.
     */
    public function isQuietAt(\DateTimeImmutable $instant): bool
    {
        if (!$this->isOn) {
            return false;
        }

        $localHour = (int) $instant->setTimezone($this->recipientTimeZone)->format('G');

        return $localHour >= self::STARTS_AT_HOUR || $localHour < self::ENDS_AT_HOUR;
    }

    /**
     * When the quiet hours around the instant end: the next 09:00 on the recipient's local clock, in their time zone,
     * across a daylight saving change too. Null when it is not quiet at the instant.
     */
    public function endsAt(\DateTimeImmutable $instant): ?\DateTimeImmutable
    {
        if (!$this->isQuietAt($instant)) {
            return null;
        }

        $local = $instant->setTimezone($this->recipientTimeZone);
        if ((int) $local->format('G') >= self::STARTS_AT_HOUR) {
            $local = $local->modify('tomorrow');
        }

        // Set on the local date, not by adding hours, so a night with a daylight saving change still ends at 09:00.
        return $local->setTime(self::ENDS_AT_HOUR, 0);
    }
}
