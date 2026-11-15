<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\Organization\Api\MemberTimeZoneQueries;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Like IdentityMemberTimeZoneQueries: more than MAX_USER_IDS ids are refused before any read; only the members of the
 * one organization it knows are answered, so asked about another organization nobody is; a member whose saved zone
 * PHP does not know gets UTC; the answer is in user id order.
 */
final class InMemoryMemberTimeZones implements MemberTimeZoneQueries
{
    private int $readCount = 0;

    /**
     * @param array<string, string> $zoneNamesByMemberId the saved zone name of each member of $organizationId, by user
     *                                                   id string
     */
    public function __construct(private readonly string $organizationId, private readonly array $zoneNamesByMemberId)
    {
    }

    public function timeZonesOf(OrganizationId $organizationId, array $userIds): array
    {
        if (\count($userIds) > self::MAX_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('Ask for the time zones of at most %d people at once.', self::MAX_USER_IDS));
        }
        if ([] === $userIds) {
            return [];
        }
        ++$this->readCount;
        if ($organizationId->toString() !== $this->organizationId) {
            return [];
        }

        $zones = [];
        foreach ($userIds as $userId) {
            $zoneName = $this->zoneNamesByMemberId[$userId->toString()] ?? null;
            if (null !== $zoneName) {
                $zones[$userId->toString()] = self::timeZoneOrUtc($zoneName);
            }
        }
        ksort($zones, \SORT_STRING);

        return $zones;
    }

    public function readCount(): int
    {
        return $this->readCount;
    }

    private static function timeZoneOrUtc(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name);
        } catch (\DateInvalidTimeZoneException) {
            return new \DateTimeZone('UTC');
        }
    }
}
