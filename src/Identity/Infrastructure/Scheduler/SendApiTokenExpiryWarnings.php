<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

/**
 * Emails the owners of one organization's tokens that stop working in about a week (SendApiTokenExpiryWarningsHandler).
 * Sent to async_notify with a TenantStamp of $organizationId, which the handler refuses to differ from. The handler
 * sends it again for the next page, with the same $ranAt, so every page claims from the same range.
 */
final readonly class SendApiTokenExpiryWarnings
{
    /** A token is warned when it expires from this many days after the run up to one day later. */
    public const int WARNING_DAYS_AHEAD = 7;

    /**
     * @param string $ranAt when QueueApiTokenExpiryWarnings ran, in \DATE_ATOM
     */
    public function __construct(public string $organizationId, public string $ranAt)
    {
    }

    /**
     * The expiry range of the tokens a run at $ranAt warns: from WARNING_DAYS_AHEAD days after it, up to but not
     * including a day later. Runs at most a day apart leave no expiry out; the ranges of two runs closer than that
     * overlap, which the once-only claim makes harmless.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable} the start and the end of the range
     */
    public static function expiryRangeAfter(\DateTimeImmutable $ranAt): array
    {
        $expiresFrom = $ranAt->modify(\sprintf('+%d days', self::WARNING_DAYS_AHEAD));

        return [$expiresFrom, $expiresFrom->modify('+1 day')];
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} expiryRangeAfter() of $ranAt
     *
     * @throws \Exception when $ranAt is not a date
     */
    public function expiryRange(): array
    {
        return self::expiryRangeAfter(new \DateTimeImmutable($this->ranAt));
    }
}
