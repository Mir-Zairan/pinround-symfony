<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\StepPolicy;
use App\Organization\Application\AddMember;
use App\Organization\Application\ChangeStepPolicy;
use App\Organization\Application\CreateOrganization;
use App\Organization\Application\CreateTeam;
use App\Organization\Application\SetTeamRole;
use App\Organization\Domain\Job;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\Organizations;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\Teams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The neutral example organizations: Acme Foods with its teams Shop and Mobile, and Beta Bakery with Ben and his team
 * Ovens under the step policy team, made through the Organization commands with the system as actor. It runs after
 * SeedDemoPeople, whose people it finds by email. Lena and Omar are in Acme Foods only, so logging in as them never
 * shows the organization picker; add one of them to Beta Bakery to try it.
 *
 * Each organization's writes run in one transaction under that organization as tenant. An organization is found by
 * its slug among the organizations of its first admin, a team by its name and a member by their membership, and
 * whatever is found stays as it is. A step policy is set only in the run that creates the organization's first team
 * of the spec, so a later change of it stays.
 *
 * @phpstan-type TeamSpec array{name: string, lead: string, leadJob: Job, members: list<array{email: string, role: TeamRole, job: Job}>}
 * @phpstan-type OrganizationSpec array{name: string, slug: string, firstAdmin: string, members: list<array{email: string, roles: list<OrgRole>}>, teams: list<TeamSpec>, stepPolicy: StepPolicy|null}
 */
#[AsTaggedItem(priority: 50)]
final readonly class SeedDemoOrganizations implements DemoSeedStep
{
    private const string SARA = 'sara@acme-foods.example';
    private const string LENA = 'lena@acme-foods.example';
    private const string OMAR = 'omar@acme-foods.example';
    private const string MIA = 'mia@acme-foods.example';
    private const string RAJ = 'raj@acme-foods.example';
    private const string BEN = 'ben@beta-bakery.example';

    /** @var list<OrganizationSpec> */
    private const array ORGANIZATIONS = [
        [
            'name' => 'Acme Foods',
            'slug' => 'acme-foods',
            'firstAdmin' => self::SARA,
            'members' => [
                ['email' => self::LENA, 'roles' => [OrgRole::Admin]],
                ['email' => self::RAJ, 'roles' => [OrgRole::Manager]],
                ['email' => self::OMAR, 'roles' => []],
                ['email' => self::MIA, 'roles' => []],
            ],
            'teams' => [
                [
                    'name' => 'Shop',
                    'lead' => self::LENA,
                    'leadJob' => Job::Developer,
                    'members' => [
                        ['email' => self::OMAR, 'role' => TeamRole::Member, 'job' => Job::Developer],
                        ['email' => self::MIA, 'role' => TeamRole::Member, 'job' => Job::Tester],
                        ['email' => self::RAJ, 'role' => TeamRole::Viewer, 'job' => Job::Other],
                    ],
                ],
                [
                    'name' => 'Mobile',
                    'lead' => self::LENA,
                    'leadJob' => Job::Developer,
                    'members' => [
                        ['email' => self::OMAR, 'role' => TeamRole::Member, 'job' => Job::Developer],
                    ],
                ],
            ],
            'stepPolicy' => null,
        ],
        [
            'name' => 'Beta Bakery',
            'slug' => 'beta-bakery',
            'firstAdmin' => self::BEN,
            'members' => [],
            'teams' => [
                ['name' => 'Ovens', 'lead' => self::BEN, 'leadJob' => Job::Developer, 'members' => []],
            ],
            'stepPolicy' => StepPolicy::Team,
        ],
    ];

    public function __construct(
        private UserQueries $users,
        private DemoOrganizations $demoOrganizations,
        private Organizations $organizations,
        private Memberships $memberships,
        private Teams $teams,
        private CreateOrganization $createOrganization,
        private AddMember $addMember,
        private CreateTeam $createTeam,
        private ChangeStepPolicy $changeStepPolicy,
        private SetTeamRole $setTeamRole,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function seed(): string
    {
        $userIds = $this->findPeople();
        $counts = new SeededCounts();
        foreach (self::ORGANIZATIONS as $organization) {
            $this->seedOrganization($organization, $userIds, $counts);
        }

        return 'Organizations and teams. '.$counts->summary();
    }

    /**
     * @return array<string, UserId> by email
     *
     * @throws DemoSeedRefused before anything is written, when a person is missing
     */
    private function findPeople(): array
    {
        $userIds = [];
        foreach ([self::SARA, self::LENA, self::OMAR, self::MIA, self::RAJ, self::BEN] as $email) {
            $userIds[$email] = $this->users->idByEmail($email)
                ?? throw new DemoSeedRefused(\sprintf('The demo person %s does not exist yet, so the organizations cannot be made. Seed the people first.', $email));
        }

        return $userIds;
    }

    /**
     * @param OrganizationSpec      $spec
     * @param array<string, UserId> $userIds
     */
    private function seedOrganization(array $spec, array $userIds, SeededCounts $counts): void
    {
        $firstAdmin = $userIds[$spec['firstAdmin']];
        $existingId = $this->demoOrganizations->idBySlug($spec['slug']);
        $organizationId = $existingId ?? $this->organizations->nextIdentity();

        $this->inTransactionOf($organizationId, function () use ($spec, $userIds, $counts, $firstAdmin, $existingId, $organizationId): void {
            $now = $this->clock->now();
            if (null === $existingId) {
                ($this->createOrganization)($organizationId, $spec['name'], $spec['slug'], $firstAdmin, null, $now);
                $counts->created('organizations', $spec['name']);
                $counts->created('members', $spec['firstAdmin']);
            } else {
                $counts->kept('organizations', $spec['name']);
                $this->addMemberIfMissing($organizationId, $spec['firstAdmin'], $firstAdmin, new OrgRoles(OrgRole::Admin), $counts);
            }
            foreach ($spec['members'] as $member) {
                $this->addMemberIfMissing($organizationId, $member['email'], $userIds[$member['email']], new OrgRoles(...$member['roles']), $counts);
            }
            $createdATeam = false;
            foreach ($spec['teams'] as $team) {
                $createdATeam = $this->seedTeam($organizationId, $team, $userIds, $counts, $now) || $createdATeam;
            }
            if (null !== $spec['stepPolicy'] && $createdATeam) {
                ($this->changeStepPolicy)($organizationId, $spec['stepPolicy'], null, $now);
            }
        });
    }

    /**
     * @return string|null the email of the demo organization's first admin; null when the slug is not a demo one
     */
    public static function firstAdminOf(string $slug): ?string
    {
        $organization = array_find(self::ORGANIZATIONS, static fn (array $spec): bool => $spec['slug'] === $slug);

        return $organization['firstAdmin'] ?? null;
    }

    private function addMemberIfMissing(OrganizationId $organizationId, string $email, UserId $userId, OrgRoles $orgRoles, SeededCounts $counts): void
    {
        if (null !== $this->memberships->of($organizationId, $userId)) {
            $counts->kept('members', $email);

            return;
        }
        ($this->addMember)($organizationId, $userId, $orgRoles, null, $this->clock->now());
        $counts->created('members', $email);
    }

    /**
     * @param TeamSpec              $spec
     * @param array<string, UserId> $userIds
     *
     * @return bool whether the team was created now
     */
    private function seedTeam(OrganizationId $organizationId, array $spec, array $userIds, SeededCounts $counts, \DateTimeImmutable $now): bool
    {
        $teamId = $this->findTeamByName($spec['name']);
        $isNew = null === $teamId;
        if (null === $teamId) {
            $teamId = ($this->createTeam)($organizationId, $spec['name'], $userIds[$spec['lead']], $spec['leadJob'], null, $now);
            $counts->created('teams', $spec['name']);
        } else {
            $counts->kept('teams', $spec['name']);
        }

        foreach ($spec['members'] as $member) {
            $label = \sprintf('%s in %s', $member['email'], $spec['name']);
            if (null !== $this->teams->byId($teamId)?->memberOf($userIds[$member['email']])) {
                $counts->kept('team members', $label);

                continue;
            }
            ($this->setTeamRole)($teamId, $userIds[$member['email']], $member['role'], $member['job'], null, $now);
            $counts->created('team members', $label);
        }

        return $isNew;
    }

    /**
     * Row-level security limits the query to the organization of the transaction. Team names are unique without
     * regard to letter case.
     */
    private function findTeamByName(string $name): ?TeamId
    {
        $id = $this->connection->fetchOne('SELECT id FROM org_team WHERE lower(name) = lower(?)', [$name]);

        return \is_string($id) ? new TeamId($id) : null;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inTransactionOf(OrganizationId $organizationId, \Closure $work): mixed
    {
        $this->tenantContext->set($organizationId);
        try {
            return $this->entityManager->wrapInTransaction(static fn (): mixed => $work());
        } finally {
            // The tenant may only change once nothing of the organization is loaded or open.
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }
    }
}
