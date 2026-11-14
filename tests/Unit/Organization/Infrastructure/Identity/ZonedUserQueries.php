<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Identity;

use App\Identity\Api\UserQueries;
use App\SharedKernel\Domain\UserId;

/**
 * Knows the zones it is given and answers timeZonesOf like DbalUserQueries: capped, known ids only, each once, keyed by
 * id string, in id order, UTC for a zone PHP does not know. Records the ids of each timeZonesOf call.
 */
final class ZonedUserQueries implements UserQueries
{
    /** @var list<list<string>> */
    public private(set) array $timeZonesOfReads = [];

    /**
     * @param array<string, string> $timeZoneNamesByUserId
     */
    public function __construct(private readonly array $timeZoneNamesByUserId)
    {
    }

    public function idByEmail(string $email): ?UserId
    {
        throw new \LogicException('Only timeZonesOf is expected.');
    }

    public function byIds(array $userIds): array
    {
        throw new \LogicException('Only timeZonesOf is expected.');
    }

    public function timeZonesOf(array $userIds): array
    {
        if (\count($userIds) > self::MAX_TIME_ZONE_USER_IDS) {
            throw new \InvalidArgumentException('Ask for the time zones of at most 100 people at once.');
        }
        $askedIds = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->timeZonesOfReads[] = $askedIds;
        $zones = [];
        foreach (array_unique($askedIds) as $userId) {
            if (isset($this->timeZoneNamesByUserId[$userId])) {
                $name = $this->timeZoneNamesByUserId[$userId];
                $zones[$userId] = new \DateTimeZone(\in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $name : 'UTC');
            }
        }
        ksort($zones);

        return $zones;
    }
}
