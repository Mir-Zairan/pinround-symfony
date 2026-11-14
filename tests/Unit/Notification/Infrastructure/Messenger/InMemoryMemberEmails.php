<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Organization\Api\MemberEmailQueries;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Like the real one: a person it does not know is left out, the answer is in user id order, and more than
 * MAX_USER_IDS ids are refused before any read.
 */
final class InMemoryMemberEmails implements MemberEmailQueries
{
    public int $readCount = 0;

    /**
     * @param array<string, string> $addressesByUserId
     */
    public function __construct(private readonly array $addressesByUserId)
    {
    }

    public function emailsOf(OrganizationId $organizationId, array $userIds): array
    {
        if (\count($userIds) > self::MAX_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('At most %d people are asked for at once.', self::MAX_USER_IDS));
        }
        if ([] === $userIds) {
            return [];
        }
        ++$this->readCount;
        $addresses = [];
        foreach ($userIds as $userId) {
            if (isset($this->addressesByUserId[$userId->toString()])) {
                $addresses[$userId->toString()] = $this->addressesByUserId[$userId->toString()];
            }
        }
        ksort($addresses);

        return $addresses;
    }
}
