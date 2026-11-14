<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\Notification\Application\Notify\DeliveryTimes;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeliveryTimesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000005301';
    private const string LENA = '0197a3c0-0000-7000-8000-000000005311';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000005312';

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function instantsAndTheirDeliveryInBerlin(): iterable
    {
        // 3 March 2026 in Berlin is UTC+1.
        yield '22:00 in Berlin waits until 09:00 the next day' => ['2026-03-03T21:00:00Z', '2026-03-04T09:00:00+01:00'];
        yield '08:59 in Berlin waits until 09:00 that day' => ['2026-03-03T07:59:00Z', '2026-03-03T09:00:00+01:00'];
        yield '09:00 in Berlin is delivered at once' => ['2026-03-03T08:00:00Z', null];
    }

    #[Test]
    #[DataProvider('instantsAndTheirDeliveryInBerlin')]
    public function withQuietHoursOnItHoldsUntil09OnTheRecipientsClock(string $instant, ?string $expectedDeliverAfter): void
    {
        $deliveryTimes = new DeliveryTimes(self::pingSettingsQueries(true), new InMemoryMemberTimeZones([self::LENA => 'Europe/Berlin']));

        $deliverAfter = $deliveryTimes->deliverAfterFor(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new \DateTimeImmutable($instant));

        self::assertSame($expectedDeliverAfter, $deliverAfter?->format(\DATE_ATOM));
    }

    #[Test]
    public function withQuietHoursOffNothingIsHeldAndNoZoneIsRead(): void
    {
        $memberTimeZones = new InMemoryMemberTimeZones([self::LENA => 'Europe/Berlin']);
        $deliveryTimes = new DeliveryTimes(self::pingSettingsQueries(false), $memberTimeZones);

        self::assertNull($deliveryTimes->deliverAfterFor(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new \DateTimeImmutable('2026-03-03T21:00:00Z')));
        self::assertSame(0, $memberTimeZones->readCount());
    }

    #[Test]
    public function aRecipientWithNoKnownZoneIsTimedOnUtc(): void
    {
        $deliveryTimes = new DeliveryTimes(self::pingSettingsQueries(true), new InMemoryMemberTimeZones([self::LENA => 'Europe/Berlin']));

        // 08:30 UTC is 09:30 in Berlin, so only the UTC fallback holds it.
        $deliverAfter = $deliveryTimes->deliverAfterFor(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), new \DateTimeImmutable('2026-03-03T08:30:00Z'));

        self::assertSame('2026-03-03T09:00:00+00:00', $deliverAfter?->format(\DATE_ATOM));
    }

    private static function pingSettingsQueries(bool $isQuietHoursOn): PingSettingsQueries
    {
        return new readonly class($isQuietHoursOn) implements PingSettingsQueries {
            public function __construct(private bool $isQuietHoursOn)
            {
            }

            public function pingLimitHoursOf(OrganizationId $organizationId): int
            {
                throw new \LogicException('DeliveryTimes does not read the ping limit.');
            }

            public function isAutoClearOn(OrganizationId $organizationId): bool
            {
                throw new \LogicException('DeliveryTimes does not read the auto-clear switch.');
            }

            public function isQuietHoursOn(OrganizationId $organizationId): bool
            {
                return $this->isQuietHoursOn;
            }
        };
    }
}
