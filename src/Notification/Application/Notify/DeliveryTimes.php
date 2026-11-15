<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

use App\Notification\Domain\QuietHours;
use App\Organization\Api\MemberTimeZoneQueries;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * When something made for one person shows to them: at once, or at the end of their quiet hours when the organization
 * has quiet hours on.
 */
final readonly class DeliveryTimes
{
    public function __construct(
        private PingSettingsQueries $pingSettingsQueries,
        private MemberTimeZoneQueries $memberTimeZoneQueries,
    ) {
    }

    /**
     * Reads under the open transaction's tenant, which must be $organizationId; under another, the switch reads as off.
     * No zone query while quiet hours are off. A recipient whose zone is unknown is timed on UTC.
     *
     * @return \DateTimeImmutable|null the next 09:00 on the recipient's clock, in their zone; null when it shows at once
     */
    public function deliverAfterFor(OrganizationId $organizationId, UserId $recipient, \DateTimeImmutable $at): ?\DateTimeImmutable
    {
        if (!$this->pingSettingsQueries->isQuietHoursOn($organizationId)) {
            return null;
        }

        $recipientTimeZone = $this->memberTimeZoneQueries->timeZonesOf($organizationId, [$recipient])[$recipient->toString()] ?? new \DateTimeZone('UTC');

        return new QuietHours(true, $recipientTimeZone)->endsAt($at);
    }
}
