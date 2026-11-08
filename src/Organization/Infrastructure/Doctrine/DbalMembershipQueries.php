<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\TeamRole;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
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

    public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
    {
        $orgRoleNames = $this->connection->fetchOne(
            'SELECT array_to_json(org_roles) FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [$organizationId->toString(), $userId->toString()],
        );
        if (false === $orgRoleNames) {
            return Roles::none();
        }
        $orgRoles = array_map(self::orgRole(...), self::stringList($orgRoleNames));

        $teamIds = [];
        $leadTeamIds = [];
        $teamRows = $this->connection->fetchAllAssociative(
            'SELECT team_id, team_role FROM org_team_member WHERE organization_id = ? AND user_id = ? ORDER BY team_id',
            [$organizationId->toString(), $userId->toString()],
        );
        foreach ($teamRows as $teamRow) {
            $teamId = self::teamId($teamRow['team_id']);
            $teamIds[] = $teamId;
            if (TeamRole::Lead === self::teamRole($teamRow['team_role'])) {
                $leadTeamIds[] = $teamId;
            }
        }

        return new Roles(
            \in_array(OrgRole::Admin, $orgRoles, true),
            \in_array(OrgRole::Manager, $orgRoles, true),
            $teamIds,
            $leadTeamIds,
        );
    }

    public function teamsOf(OrganizationId $organizationId): array
    {
        return array_map(
            self::teamId(...),
            $this->connection->fetchFirstColumn('SELECT id FROM org_team WHERE organization_id = ? ORDER BY id', [$organizationId->toString()]),
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $json): array
    {
        $names = \is_string($json) ? json_decode($json, true, 2, \JSON_THROW_ON_ERROR) : null;
        if (!\is_array($names) || !array_is_list($names) || array_filter($names, is_string(...)) !== $names) {
            throw new \LogicException('org_membership.org_roles is not a list of role names.');
        }

        return $names;
    }

    private static function orgRole(string $name): OrgRole
    {
        return OrgRole::tryFrom($name) ?? throw new \LogicException('org_membership holds an unknown org role.');
    }

    private static function teamRole(mixed $name): TeamRole
    {
        return (\is_string($name) ? TeamRole::tryFrom($name) : null) ?? throw new \LogicException('org_team_member holds an unknown team role.');
    }

    private static function teamId(mixed $teamId): TeamId
    {
        if (!\is_string($teamId)) {
            throw new \LogicException('A team id from the database is not a uuid.');
        }

        return new TeamId($teamId);
    }
}
