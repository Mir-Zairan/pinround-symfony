<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Identity\Api\UserQueries;
use App\SharedKernel\Domain\UserId;

/**
 * Knows the users it is given, by email compared trimmed and in lowercase, as DbalUserQueries does.
 */
final readonly class FixedUserQueries implements UserQueries
{
    /**
     * @param array<string, string> $userIdsByEmail lowercase email => user id
     */
    public function __construct(private array $userIdsByEmail = [])
    {
    }

    public function idByEmail(string $email): ?UserId
    {
        $userId = $this->userIdsByEmail[mb_strtolower(trim($email))] ?? null;

        return null === $userId ? null : new UserId($userId);
    }

    public function byIds(array $userIds): array
    {
        throw new \LogicException('Only idByEmail is expected.');
    }
}
