<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Identity;

use App\Identity\Api\UserQueries;
use App\SharedKernel\Domain\UserId;

/**
 * Knows the addresses of the active users it is given and answers emailsOf like DbalUserQueries: capped, known ids
 * only, each once, keyed by id string, in id order; a user it does not know, like a blocked one, is left out. Records
 * the ids of each emailsOf call.
 */
final class AddressedUserQueries implements UserQueries
{
    /** @var list<list<string>> */
    public private(set) array $emailsOfReads = [];

    /**
     * @param array<string, string> $emailsByActiveUserId
     */
    public function __construct(private readonly array $emailsByActiveUserId)
    {
    }

    public function idByEmail(string $email): ?UserId
    {
        throw new \LogicException('Only emailsOf is expected.');
    }

    public function byIds(array $userIds): array
    {
        throw new \LogicException('Only emailsOf is expected.');
    }

    public function timeZonesOf(array $userIds): array
    {
        throw new \LogicException('Only emailsOf is expected.');
    }

    public function emailsOf(array $userIds): array
    {
        if (\count($userIds) > self::MAX_EMAIL_USER_IDS) {
            throw new \InvalidArgumentException('Ask for the email addresses of at most 100 people at once.');
        }
        $askedIds = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->emailsOfReads[] = $askedIds;
        $emails = [];
        foreach (array_unique($askedIds) as $userId) {
            if (isset($this->emailsByActiveUserId[$userId])) {
                $emails[$userId] = mb_strtolower(trim($this->emailsByActiveUserId[$userId]));
            }
        }
        ksort($emails);

        return $emails;
    }
}
