<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The person behind an AI request (a summary or a "Check with AI"), read fresh: with their roles for the policy and the
 * ticket visibility, or only as a member for a poll. A user who is no member gets no actor: rolesOf alone would give
 * them no roles, which still sees the tickets for everyone. Both read under row-level security, so they need an open
 * transaction whose tenant is $organizationId.
 */
final readonly class AiRequesters
{
    public function __construct(private MembershipQueries $membershipQueries)
    {
    }

    /**
     * @return ActorContext|null null when the user is no member of the organization
     */
    public function actorOf(UserId $userId, OrganizationId $organizationId): ?ActorContext
    {
        if (!$this->isMember($userId, $organizationId)) {
            return null;
        }

        return new ActorContext($userId, $organizationId, $this->membershipQueries->rolesOf($userId, $organizationId));
    }

    /**
     * One query, without the roles, for a read that only needs to know the user still belongs.
     */
    public function isMember(UserId $userId, OrganizationId $organizationId): bool
    {
        return [] !== $this->membershipQueries->membersAmong($organizationId, [$userId]);
    }
}
