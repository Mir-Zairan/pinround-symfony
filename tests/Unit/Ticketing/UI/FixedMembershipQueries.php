<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Memberships fixed up front, answered like DbalMembershipQueries::membersAmong: only members of the asked organization,
 * each once, in id order whatever order they were asked in. Records each membersAmong call that would have read the
 * database, so a test can count them; an empty list reads nothing, as in the real one.
 */
final class FixedMembershipQueries implements MembershipQueries
{
    /** @var list<list<string>> the user ids of each membersAmong call that read */
    public private(set) array $membersAmongReads = [];

    /**
     * @param array<string, list<string>> $memberIdsByOrganization user ids by organization id
     */
    public function __construct(private readonly array $memberIdsByOrganization)
    {
    }

    public function organizationsOf(UserId $userId): array
    {
        throw new \LogicException('The next step box asks only which of the ticket\'s people are members.');
    }

    public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
    {
        throw new \LogicException('The next step box asks only which of the ticket\'s people are members.');
    }

    public function membersAmong(OrganizationId $organizationId, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        $askedKeys = array_map(static fn (UserId $userId): string => $userId->toString(), $userIds);
        $this->membersAmongReads[] = $askedKeys;
        $memberKeys = array_values(array_unique(array_intersect($askedKeys, $this->memberIdsByOrganization[$organizationId->toString()] ?? [])));
        sort($memberKeys);

        return array_map(static fn (string $userKey): UserId => new UserId($userKey), $memberKeys);
    }
}
