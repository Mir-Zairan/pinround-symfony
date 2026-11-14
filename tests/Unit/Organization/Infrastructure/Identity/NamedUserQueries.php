<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Identity;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\SharedKernel\Domain\UserId;

/**
 * Knows the names it is given and answers byIds like DbalUserQueries: known ids only, each once, keyed by id string, in
 * id order. Records the ids of each byIds call, so a test can see what was read.
 */
final class NamedUserQueries implements UserQueries
{
    /** @var list<list<string>> */
    public private(set) array $byIdsReads = [];

    /**
     * @param array<string, string> $displayNamesByUserId
     */
    public function __construct(private readonly array $displayNamesByUserId)
    {
    }

    public function idByEmail(string $email): ?UserId
    {
        throw new \LogicException('Only byIds is expected.');
    }

    public function byIds(array $userIds): array
    {
        $askedIds = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->byIdsReads[] = $askedIds;
        $views = [];
        foreach (array_unique($askedIds) as $userId) {
            if (isset($this->displayNamesByUserId[$userId])) {
                $views[$userId] = new UserView(new UserId($userId), $this->displayNamesByUserId[$userId]);
            }
        }
        ksort($views);

        return $views;
    }

    public function timeZonesOf(array $userIds): array
    {
        throw new \LogicException('Only byIds is expected.');
    }
}
