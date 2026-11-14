<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;

/**
 * The people, team and comments the comment use case tests act on, written and removed through the migrator under each
 * organization as tenant. The caller makes sure no transaction is open.
 */
final readonly class ThreadRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    /**
     * @param string $orgRoles a PostgreSQL text array, such as {} or {admin}
     */
    public function addMembershipOf(string $organizationId, string $userId, string $orgRoles): void
    {
        $this->asTenant($organizationId, fn (): int|string => $this->migrator->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $userId, $orgRoles],
        ));
    }

    /**
     * @param list<string> $memberIds people who join the team as members, besides its lead
     */
    public function addTeamOf(string $organizationId, string $teamId, string $leadId, array $memberIds): void
    {
        $this->asTenant($organizationId, function () use ($organizationId, $teamId, $leadId, $memberIds): void {
            $this->migrator->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, 'Shop', 'shop', ?, '2027-03-15 10:00:00+00')",
                [$organizationId, $teamId, $leadId],
            );
            foreach ([[$leadId, 'lead'], ...array_map(static fn (string $memberId): array => [$memberId, 'member'], $memberIds)] as [$userId, $teamRole]) {
                $this->migrator->executeStatement(
                    "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, 'developer', '2027-03-15 10:00:00+00')",
                    [$organizationId, $teamId, $userId, $teamRole],
                );
            }
        });
    }

    public function addCommentOf(string $organizationId, string $ticketId, string $messageId, string $authorId, string $body, string $createdAt, ?string $removedAt = null): void
    {
        $this->asTenant($organizationId, fn (): int|string => $this->migrator->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at, removed_at) VALUES (?, ?, ?, ?, 'comment', ?, ?, ?)",
            [$organizationId, $messageId, $ticketId, $authorId, $body, $createdAt, $removedAt],
        ));
    }

    public function addOpenQuestionOf(string $organizationId, string $ticketId, string $messageId, string $authorId, string $toUserId, string $body, string $createdAt): void
    {
        $this->asTenant($organizationId, fn (): int|string => $this->migrator->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, created_at) VALUES (?, ?, ?, ?, 'question', ?, ?, ?)",
            [$organizationId, $messageId, $ticketId, $authorId, $toUserId, $body, $createdAt],
        ));
    }

    /**
     * Removes the organizations' tickets with their messages, then their memberships and teams.
     */
    public function removeAllOf(string ...$organizationIds): void
    {
        new TicketRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        new OrganizationRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function asTenant(string $organizationId, \Closure $work): void
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator->transactional($work);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
