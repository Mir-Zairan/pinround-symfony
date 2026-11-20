<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Organization\Application\NotInTheOrganization;
use App\Organization\Application\OrganizationPeopleQueries;
use App\Organization\Application\OrganizationPerson;
use App\Organization\Application\PersonInTeam;
use App\Organization\Domain\OrgRole;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Names live in Identity's idn_user, which a tenant module never joins (data-model.md), so the name order is made here
 * over every member's name: the member ids, then their names through UserQueries, then the roles and teams of the
 * page's people only. The largest organization has about 2,000 people (performance-and-scale.md), which keeps the
 * first two reads small.
 */
final readonly class DbalOrganizationPeopleQueries implements OrganizationPeopleQueries
{
    public function __construct(private Connection $connection, private UserQueries $userQueries)
    {
    }

    public function pageOf(OrganizationId $organizationId, int $limit, ?UserId $after = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('A page of people holds 1 to %d, not %d.', self::MAX_LIMIT, $limit));
        }

        $memberIds = array_map(
            self::userIdOf(...),
            $this->connection->fetchFirstColumn('SELECT user_id FROM org_membership WHERE organization_id = ?', [$organizationId->toString()]),
        );
        $members = [] === $memberIds ? [] : array_values($this->userQueries->byIds($memberIds));
        usort($members, self::compareByName(...));

        $start = 0;
        if (null !== $after) {
            $afterIndex = array_find_key($members, static fn (UserView $member): bool => $member->id->equals($after));
            if (null === $afterIndex) {
                throw new NotInTheOrganization('The person the page of people starts after is not in the organization.');
            }
            $start = $afterIndex + 1;
        }
        $page = \array_slice($members, $start, $limit);
        if ([] === $page) {
            return [];
        }

        $rolesAndTeams = $this->rolesAndTeamsOf($organizationId, $page);

        return array_map(
            static fn (UserView $member): OrganizationPerson => new OrganizationPerson(
                $member->id,
                $member->displayName,
                $rolesAndTeams[$member->id->toString()]['isAdmin'] ?? false,
                $rolesAndTeams[$member->id->toString()]['isManager'] ?? false,
                $rolesAndTeams[$member->id->toString()]['teams'] ?? [],
            ),
            $page,
        );
    }

    /**
     * Case is ignored first so "lena" sits beside "Lena"; the id then orders people with the same name, so every page
     * starts where the last one ended.
     */
    private static function compareByName(UserView $first, UserView $second): int
    {
        return [mb_strtolower($first->displayName), $first->id->toString()] <=> [mb_strtolower($second->displayName), $second->id->toString()];
    }

    /**
     * One query for the page's people, each person's teams by team name ignoring case, then team id.
     *
     * @param non-empty-list<UserView> $people
     *
     * @return array<string, array{isAdmin: bool, isManager: bool, teams: list<PersonInTeam>}> by user id
     */
    private function rolesAndTeamsOf(OrganizationId $organizationId, array $people): array
    {
        // One array literal parameter keeps the SQL text the same for any page size; a UserId is always a uuid, so it
        // needs no quoting inside the literal.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT membership.user_id, ? = ANY(membership.org_roles) AS is_admin, ? = ANY(membership.org_roles) AS is_manager,'
            .' member.team_id, team.name AS team_name, member.team_role, member.job'
            .' FROM org_membership AS membership'
            .' LEFT JOIN (org_team_member AS member'
            .'   JOIN org_team AS team ON team.organization_id = member.organization_id AND team.id = member.team_id)'
            .' ON member.organization_id = membership.organization_id AND member.user_id = membership.user_id'
            .' WHERE membership.organization_id = ? AND membership.user_id = ANY(?::uuid[])'
            .' ORDER BY lower(team.name), member.team_id',
            [
                OrgRole::Admin->value,
                OrgRole::Manager->value,
                $organizationId->toString(),
                '{'.implode(',', array_map(static fn (UserView $person): string => $person->id->toString(), $people)).'}',
            ],
        );

        $rolesAndTeams = [];
        foreach ($rows as $row) {
            $userId = self::userIdOf($row['user_id'])->toString();
            $rolesAndTeams[$userId] ??= ['isAdmin' => self::flagOf($row['is_admin']), 'isManager' => self::flagOf($row['is_manager']), 'teams' => []];
            if (null !== $row['team_id']) {
                $rolesAndTeams[$userId]['teams'][] = new PersonInTeam(
                    \is_string($row['team_id']) ? new TeamId($row['team_id']) : throw new \LogicException('A team id from the database is not a uuid.'),
                    \is_string($row['team_name']) ? $row['team_name'] : throw new \LogicException('A team name from the database is not a string.'),
                    TeamMemberRows::teamRoleOf($row['team_role']),
                    TeamMemberRows::jobOf($row['job']),
                );
            }
        }

        return $rolesAndTeams;
    }

    private static function userIdOf(mixed $userId): UserId
    {
        return \is_string($userId) ? new UserId($userId) : throw new \LogicException('A member id from the database is not a uuid.');
    }

    private static function flagOf(mixed $flag): bool
    {
        return \is_bool($flag) ? $flag : throw new \LogicException('An org role flag from the database is not a boolean.');
    }
}
