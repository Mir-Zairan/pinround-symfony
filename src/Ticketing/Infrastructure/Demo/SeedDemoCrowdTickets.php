<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateProject\CreateProject;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Projects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Beta Bakery's project CROWD, owned by its team Crowd (Organization's SeedDemoCrowdTeam), with a few open tickets: two
 * for Person0042, one for Person0040 and one for Ben, so the person picker sorts Person0040 above Person0004 by its
 * count and a pick of Person0042 leaves exactly two rows. A ticket whose title CROWD already holds stays as it is. Dev only,
 * as SeedDemoCrowdPeople.
 */
#[AsTaggedItem(priority: 20)]
#[When('dev')]
final readonly class SeedDemoCrowdTickets implements DemoSeedStep
{
    private const string ORGANIZATION = 'beta-bakery';
    private const string TEAM = 'Crowd';
    private const string KEY = 'CROWD';
    private const string NAME = 'Crowd';
    private const string BEN = 'ben@beta-bakery.example';
    /** Title => the assignee's email. */
    private const array TICKETS = [
        'Oven timer drifts after an hour' => 'person0042@beta-bakery.example',
        'Proofing log misses the night reading' => 'person0042@beta-bakery.example',
        'Flour order form drops the last line' => 'person0040@beta-bakery.example',
        'Add a cleaning slot to the oven plan' => self::BEN,
    ];

    public function __construct(
        private DemoOrganizations $demoOrganizations,
        private TeamQueries $teamQueries,
        private UserQueries $userQueries,
        private Projects $projects,
        private CreateProject $createProject,
        private TicketCommands $ticketCommands,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function seed(): string
    {
        $organizationId = $this->demoOrganizations->idBySlug(self::ORGANIZATION)
            ?? throw new DemoSeedRefused('The demo organization Beta Bakery does not exist yet, so the Crowd tickets cannot be made. Seed the organizations first.');
        $ben = $this->userQueries->idByEmail(self::BEN)
            ?? throw new DemoSeedRefused('The demo person Ben does not exist yet, so the Crowd tickets cannot be made.');

        $this->entityManager->clear();
        $this->tenantContext->set($organizationId);
        try {
            $created = $this->entityManager->wrapInTransaction(function () use ($organizationId, $ben): int {
                $team = array_find(
                    $this->teamQueries->allSummariesOf($organizationId),
                    static fn (TeamSummary $summary): bool => 0 === strcasecmp($summary->name(), self::TEAM),
                ) ?? throw new DemoSeedRefused('Beta Bakery has no team Crowd yet, so the Crowd tickets cannot be made. Seed the Crowd team first.');
                $projectId = $this->projects->byKey(new ProjectKey(self::KEY))?->id()->toString()
                    ?? ($this->createProject)($organizationId, $team->id(), self::KEY, self::NAME)->toString();
                // Creating a ticket locks and reloads the project, which Doctrine cannot do to a readonly entity it still holds,
                // so the unit of work is written and emptied before each ticket.
                $this->entityManager->flush();
                $this->entityManager->clear();
                $existingTitles = array_fill_keys(array_filter($this->connection->fetchFirstColumn('SELECT title FROM tkt_ticket WHERE project_id = ?', [$projectId]), 'is_string'), true);

                $created = 0;
                foreach (self::TICKETS as $title => $assigneeEmail) {
                    if (isset($existingTitles[$title])) {
                        continue;
                    }
                    $assignee = $this->userQueries->idByEmail($assigneeEmail)
                        ?? throw new DemoSeedRefused(\sprintf('The person %s does not exist yet, so the Crowd tickets cannot be made.', $assigneeEmail));
                    $this->ticketCommands->create($organizationId, $ben, $projectId, $title, 'Seeded for the person picker.', 'task', 'normal', $assignee, \DateTimeImmutable::createFromInterface($this->clock->now()));
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    ++$created;
                }

                return $created;
            });
        } finally {
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }

        return \sprintf('Crowd tickets: created %d, kept %d.', $created, \count(self::TICKETS) - $created);
    }
}
