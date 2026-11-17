<?php

declare(strict_types=1);

namespace App\Assist\Application\Summaries;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The person behind a summary request with their roles, read fresh, for the policy and the ticket visibility. A user
 * who is no member gets no actor: rolesOf alone would give them no roles, which still sees the tickets for everyone.
 */
final readonly class SummaryRequesters
{
    public function __construct(private MembershipQueries $membershipQueries)
    {
    }

    /**
     * Reads under row-level security, so it needs an open transaction whose tenant is $organizationId.
     *
     * @return ActorContext|null null when the user is no member of the organization
     */
    public function actorOf(UserId $userId, OrganizationId $organizationId): ?ActorContext
    {
        if ([] === $this->membershipQueries->membersAmong($organizationId, [$userId])) {
            return null;
        }

        return new ActorContext($userId, $organizationId, $this->membershipQueries->rolesOf($userId, $organizationId));
    }
}
