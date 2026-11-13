<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Workflow\WorkflowRows;
use Doctrine\DBAL\Connection;

/**
 * An organization with the core steps Backlog and To do, a team that owns the project SHOP, and its people, so
 * TicketCommands can create a ticket in SHOP. Written as the migrator, which may also delete the users again.
 */
final readonly class ShopTeamRows
{
    public const string BACKLOG_STEP = '0197a3c0-0000-7000-8000-00000000a731';
    public const string TO_DO_STEP = '0197a3c0-0000-7000-8000-00000000a732';

    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    /**
     * @param array<string, array{string, string, string}> $people the team's people by user id: name, team role (lead,
     *                                                             member or viewer) and job (developer, tester or other)
     */
    public function add(string $organizationId, string $teamId, string $projectId, string $leadUserId, array $people): void
    {
        foreach ($people as $userId => [$name]) {
            $this->addUser($userId, $name);
        }
        new TicketRows($this->migrator, $this->tenantContext)->addKeyedProjectOf($organizationId, $projectId, $teamId, 'SHOP', 7);
        $this->asTenant($organizationId, function () use ($organizationId, $teamId, $leadUserId): void {
            foreach ([[self::BACKLOG_STEP, 'Backlog', 'backlog', 0], [self::TO_DO_STEP, 'To do', 'todo', 1]] as [$stepId, $name, $coreCode, $position]) {
                $this->migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)",
                    [$organizationId, $stepId, $name, $coreCode, $position],
                );
            }
            $this->migrator->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, 'Shop', 'shop', ?, '2027-03-15 10:00:00+00')",
                [$organizationId, $teamId, $leadUserId],
            );
        });
        foreach ($people as $userId => [, $teamRole, $job]) {
            $this->addMember($organizationId, $teamId, $userId, $teamRole, $job);
        }
    }

    public function addUser(string $userId, string $name): void
    {
        $this->migrator->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
             VALUES (?, ?, ?, 'active', 'en', 'UTC', '2027-03-15 10:00:00+00', 'a-stamp')",
            [$userId, strtolower($name).'.'.substr($userId, -4).'@acme-foods.example', $name],
        );
    }

    /**
     * Adds the person to the organization and the team, without a user row; byIds then leaves them out.
     */
    public function addMember(string $organizationId, string $teamId, string $userId, string $teamRole, string $job): void
    {
        $this->asTenant($organizationId, function () use ($organizationId, $teamId, $userId, $teamRole, $job): void {
            $this->migrator->executeStatement(
                "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
                [$organizationId, $userId],
            );
            $this->migrator->executeStatement(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
                [$organizationId, $teamId, $userId, $teamRole, $job],
            );
        });
    }

    /**
     * @param list<string> $userIds
     */
    public function removeAllOf(array $userIds, string ...$organizationIds): void
    {
        new TicketRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        new WorkflowRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        new OrganizationRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
        foreach ($userIds as $userId) {
            $this->migrator->executeStatement('DELETE FROM idn_user WHERE id = ?', [$userId]);
        }
    }

    /**
     * @param \Closure(): void $work
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
