<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\Organization\Domain\TeamSlug;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Committed teams and team roles for functional tests, next to LogsInUsers, whose committedAs it writes through and
 * whose tearDown deletes them with their organization.
 */
trait CreatesTeams
{
    /**
     * The team with its lead, who gets the team role lead.
     */
    private function createTeam(OrganizationId $organizationId, string $name, User $lead): TeamId
    {
        $teamId = new TeamId(Uuid::v7()->toRfc4122());
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, $teamId, $name, $lead): int {
            $migrator->executeStatement(
                "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, '2026-11-08 09:00:00+00')",
                [$organizationId->toString(), $teamId->toString(), $name, TeamSlug::fromName($name)->toString(), $lead->id()->toString()],
            );

            return self::insertTeamRole($migrator, $organizationId, $teamId, $lead, 'lead');
        });

        return $teamId;
    }

    /**
     * @param 'member'|'viewer' $teamRole
     */
    private function addTeamRole(User $user, OrganizationId $organizationId, TeamId $teamId, string $teamRole): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int => self::insertTeamRole($migrator, $organizationId, $teamId, $user, $teamRole));
    }

    private static function insertTeamRole(Connection $migrator, OrganizationId $organizationId, TeamId $teamId, User $user, string $teamRole): int
    {
        return (int) $migrator->executeStatement(
            "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, 'other', '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), $teamId->toString(), $user->id()->toString(), $teamRole],
        );
    }
}
