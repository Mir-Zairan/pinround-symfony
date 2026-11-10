<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Acme Foods with its people and the teams Shop and Mobile, and committed projects, for the team page's tests. Next to
 * LogsInUsers and CreatesTeams, through whose committedAs it writes; removeCommittedProjects must run before
 * LogsInUsers' tearDown deletes the organizations.
 */
trait CreatesAcmeFoodsTeams
{
    private OrganizationId $acmeFoods;

    private TeamId $shop;

    private TeamId $mobile;

    /**
     * Lena leads Shop and Mobile, Omar is a member of Shop and Mia its viewer; Raj is a Manager and Sara an Admin, of
     * no team. None has an org role but Raj and Sara.
     *
     * @param 'member'|'viewer'|null $miaTeamRole Mia's role in Shop, null for none
     *
     * @return array<string, User> by first name
     */
    private function createAcmeFoodsPeople(?string $miaTeamRole = 'viewer'): array
    {
        $this->acmeFoods = $this->createOrganization('Acme Foods');
        $people = [];
        foreach (['Lena' => '{}', 'Omar' => '{}', 'Mia' => '{}', 'Raj' => '{manager}', 'Sara' => '{admin}'] as $name => $orgRoles) {
            $people[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($people[$name], $this->acmeFoods);
            $this->setOrgRoles($people[$name], $this->acmeFoods, $orgRoles);
        }

        $this->shop = $this->createTeam($this->acmeFoods, 'Shop', $people['Lena']);
        $this->mobile = $this->createTeam($this->acmeFoods, 'Mobile', $people['Lena']);
        $this->addTeamRole($people['Omar'], $this->acmeFoods, $this->shop, 'member');
        if (null !== $miaTeamRole) {
            $this->addTeamRole($people['Mia'], $this->acmeFoods, $this->shop, $miaTeamRole);
        }

        return $people;
    }

    /**
     * @param ?TeamId $teamId null for a project that no team owns
     *
     * @return string the project's id
     */
    private function addProject(?TeamId $teamId, string $key, string $name, ?OrganizationId $organizationId = null): string
    {
        $organizationId ??= $this->acmeFoods;
        $projectId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number) VALUES (?, ?, ?, ?, ?, 1)',
            [$organizationId->toString(), $projectId, $teamId?->toString(), $key, $name],
        ));

        return $projectId;
    }

    /**
     * Through the migrator, as the app may not delete projects.
     */
    private function removeCommittedProjects(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM tkt_project WHERE organization_id = ?',
                [$organizationId],
            ));
        }
    }
}
