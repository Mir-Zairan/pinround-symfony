<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\Organization\Api\MemberTimeZoneQueries;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Like the real one: a person it does not know is left out, the answer is in user id order, and more than
 * MAX_USER_IDS ids are refused before any read.
 */
final class InMemoryMemberTimeZones implements MemberTimeZoneQueries
{
    private int $readCount = 0;

    /**
     * @param array<string, string> $zoneNamesByUserId IANA zone names by user id string
     */
    public function __construct(private readonly array $zoneNamesByUserId)
    {
    }

    public function timeZonesOf(OrganizationId $organizationId, array $userIds): array
    {
        if (\count($userIds) > self::MAX_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('At most %d people are asked for at once.', self::MAX_USER_IDS));
        }
        if ([] === $userIds) {
            return [];
        }
        ++$this->readCount;

        $zones = [];
        foreach ($userIds as $userId) {
            if (isset($this->zoneNamesByUserId[$userId->toString()])) {
                $zones[$userId->toString()] = new \DateTimeZone($this->zoneNamesByUserId[$userId->toString()]);
            }
        }
        ksort($zones);

        return $zones;
    }

    public function readCount(): int
    {
        return $this->readCount;
    }
}
