<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalMembershipQueries implements MembershipQueries
{
    public function __construct(private Connection $connection)
    {
    }

    public function organizationsOf(UserId $userId): array
    {
        $organizationIds = [];
        foreach ($this->connection->fetchFirstColumn('SELECT organization_id FROM resolve_user_orgs(?)', [$userId->toString()]) as $organizationId) {
            if (!\is_string($organizationId)) {
                throw new \LogicException('resolve_user_orgs returned a value that is not a uuid.');
            }
            $organizationIds[] = new OrganizationId($organizationId);
        }

        return $organizationIds;
    }
}
