<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Application\AddMember;
use App\Organization\Application\CreateTeam;
use App\Organization\Application\OrganizationEvents;
use App\Organization\Domain\Job;
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
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Beta Bakery's team Crowd: Ben as lead and the PEOPLE_COUNT people (a test passes fewer) Identity's SeedDemoCrowdPeople made
 * (person0001@beta-bakery.example and on) as members, so the tickets toolbar shows the person picker for a team of
 * thousands. It runs after SeedDemoOrganizations, in one transaction under Beta Bakery; whoever is in the team stays.
 * Beta Bakery's team Ovens and Acme Foods are left as they are, so no other spec sees the crowd.
 *
 * The people join as SetTeamRole would make them join, with one TeamRoleChanged each, but the team is locked, loaded
 * and saved once: SetTeamRole reloads the whole team per person, which for thousands runs out of memory. Dev
 * only, as SeedDemoCrowdPeople.
 */
#[AsTaggedItem(priority: 45)]
#[When('dev')]
final readonly class SeedDemoCrowdTeam implements DemoSeedStep
{
    public const string TEAM = 'Crowd';
    private const string ORGANIZATION = 'beta-bakery';
    private const string BEN = 'ben@beta-bakery.example';
    /** As SeedDemoCrowdPeople::COUNT; Identity's class is not this module's to read. */
    private const int PEOPLE_COUNT = 2000;
    /** AddMember flushes the whole unit of work, so it is cleared this often to keep the run linear. */
    private const int BATCH_SIZE = 100;

    public function __construct(
        private UserQueries $users,
        private DemoOrganizations $demoOrganizations,
        private CreateTeam $createTeam,
        private AddMember $addMember,
        private Teams $teams,
        private OrganizationEvents $events,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
        private int $peopleCount = self::PEOPLE_COUNT,
    ) {
    }

    public function seed(): string
    {
        $organizationId = $this->demoOrganizations->idBySlug(self::ORGANIZATION)
            ?? throw new DemoSeedRefused('The demo organization Beta Bakery does not exist yet, so the Crowd team cannot be made. Seed the organizations first.');
        $ben = $this->users->idByEmail(self::BEN)
            ?? throw new DemoSeedRefused('The demo person Ben does not exist yet, so the Crowd team cannot be made. Seed the people first.');
        $people = $this->findPeople();

        $this->tenantContext->set($organizationId);
        try {
            $added = $this->entityManager->wrapInTransaction(function () use ($organizationId, $ben, $people): int {
                $now = $this->clock->now();
                $teamId = $this->findTeam() ?? ($this->createTeam)($organizationId, self::TEAM, $ben, Job::Developer, null, $now);
                $this->addMissingMemberships($organizationId, $people, $now);

                return $this->addMissingTeamMembers($organizationId, $teamId, $people, $now);
            });
        } finally {
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }

        return \sprintf('Crowd team: added %d people, kept %d.', $added, $this->peopleCount - $added);
    }

    /**
     * @return list<UserId>
     *
     * @throws DemoSeedRefused before anything is written, when a crowd person is missing
     */
    private function findPeople(): array
    {
        $people = [];
        for ($number = 1; $number <= $this->peopleCount; ++$number) {
            $email = \sprintf('person%04d@beta-bakery.example', $number);
            $people[] = $this->users->idByEmail($email)
                ?? throw new DemoSeedRefused(\sprintf('The crowd person %s does not exist yet, so the Crowd team cannot be made. Seed the people first.', $email));
        }

        return $people;
    }

    /**
     * @param list<UserId> $people
     */
    private function addMissingMemberships(OrganizationId $organizationId, array $people, \DateTimeImmutable $now): void
    {
        $members = array_fill_keys(array_filter($this->connection->fetchFirstColumn('SELECT user_id FROM org_membership'), 'is_string'), true);
        $added = 0;
        foreach ($people as $person) {
            if (isset($members[$person->toString()])) {
                continue;
            }
            ($this->addMember)($organizationId, $person, new OrgRoles(), null, $now);
            if (0 === ++$added % self::BATCH_SIZE) {
                $this->entityManager->clear();
            }
        }
        $this->entityManager->clear();
    }

    /**
     * @param list<UserId> $people
     *
     * @return int how many joined now
     */
    private function addMissingTeamMembers(OrganizationId $organizationId, TeamId $teamId, array $people, \DateTimeImmutable $now): int
    {
        $team = $this->teams->lockById($teamId) ?? throw new DemoSeedRefused('The Crowd team was not found after it was made.');
        $joined = [];
        foreach ($people as $person) {
            if (null === $team->memberOf($person)) {
                $team->addMember($person, TeamRole::Member, Job::Developer, $now);
                $joined[] = $person;
            }
        }
        if ([] === $joined) {
            return 0;
        }
        $this->teams->save($team);
        foreach ($joined as $person) {
            $this->events->publish(new TeamRoleChanged($organizationId->toString(), $teamId->toString(), $person->toString(), null, TeamRole::Member->value, null, $now));
        }

        return \count($joined);
    }

    /**
     * Row-level security limits the query to Beta Bakery. Team names are unique without regard to letter case.
     */
    private function findTeam(): ?TeamId
    {
        $id = $this->connection->fetchOne('SELECT id FROM org_team WHERE lower(name) = lower(?)', [self::TEAM]);

        return \is_string($id) ? new TeamId($id) : null;
    }
}
