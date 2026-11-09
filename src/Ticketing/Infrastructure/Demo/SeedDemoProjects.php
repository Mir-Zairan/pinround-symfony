<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Demo;

use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use App\Ticketing\Application\CreateProject\CreateProject;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Projects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The demo project SHOP of Acme Foods, owned by its team Shop. It runs after SeedDemoOrganizations and finds Acme
 * Foods through Organization's DemoOrganizations. A project found by its key stays as it is.
 */
#[AsTaggedItem(priority: 40)]
final readonly class SeedDemoProjects implements DemoSeedStep
{
    private const string ORGANIZATION = 'acme-foods';
    private const string TEAM = 'Shop';
    private const string KEY = 'SHOP';
    private const string NAME = 'Shop';

    public function __construct(
        private DemoOrganizations $demoOrganizations,
        private TeamQueries $teamQueries,
        private Projects $projects,
        private CreateProject $createProject,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function seed(): string
    {
        $organizationId = $this->demoOrganizations->idBySlug(self::ORGANIZATION)
            ?? throw new DemoSeedRefused(\sprintf('The demo organization %s does not exist yet, so the projects cannot be made. Seed the organizations first.', self::ORGANIZATION));

        $isCreated = $this->inTransactionOf($organizationId, function () use ($organizationId): bool {
            $shopTeam = $this->findTeamByName($organizationId, self::TEAM)
                ?? throw new DemoSeedRefused(\sprintf('The demo organization %s has no team %s yet, so the projects cannot be made. Seed the organizations first.', self::ORGANIZATION, self::TEAM));
            if (null !== $this->projects->byKey(new ProjectKey(self::KEY))) {
                return false;
            }
            ($this->createProject)($organizationId, $shopTeam, self::KEY, self::NAME);

            return true;
        });

        return \sprintf('Projects: created %d, kept %d.', $isCreated ? 1 : 0, $isCreated ? 0 : 1);
    }

    /**
     * Team names are unique without regard to letter case.
     */
    private function findTeamByName(OrganizationId $organizationId, string $name): ?TeamId
    {
        $team = array_find(
            $this->teamQueries->allSummariesOf($organizationId),
            static fn (TeamSummary $summary): bool => 0 === strcasecmp($summary->name(), $name),
        );

        return $team?->id();
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
