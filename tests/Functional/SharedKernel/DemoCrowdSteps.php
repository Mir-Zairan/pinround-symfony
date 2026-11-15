<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\Api\UserQueries;
use App\Identity\Domain\Users;
use App\Identity\Infrastructure\Demo\SeedDemoCrowdPeople;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\TeamQueries;
use App\Organization\Application\AddMember;
use App\Organization\Application\CreateTeam;
use App\Organization\Application\OrganizationEvents;
use App\Organization\Domain\Teams;
use App\Organization\Infrastructure\Demo\SeedDemoCrowdTeam;
use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateProject\CreateProject;
use App\Ticketing\Domain\Projects;
use App\Ticketing\Infrastructure\Demo\SeedDemoCrowdTickets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The dev-only crowd steps of app:demo:seed, built by hand from the test container (the test environment does not
 * register them), with a crowd of $crowdSize people instead of thousands.
 */
final readonly class DemoCrowdSteps
{
    /**
     * @param positive-int $crowdSize
     */
    public function __construct(private ContainerInterface $container, private int $crowdSize)
    {
    }

    public function people(): SeedDemoCrowdPeople
    {
        return new SeedDemoCrowdPeople($this->service(Users::class), $this->service(EntityManagerInterface::class), $this->clock(), $this->crowdSize);
    }

    public function team(): SeedDemoCrowdTeam
    {
        return new SeedDemoCrowdTeam(
            $this->service(UserQueries::class),
            $this->service(DemoOrganizations::class),
            $this->service(CreateTeam::class),
            $this->service(AddMember::class),
            $this->service(Teams::class),
            $this->service(OrganizationEvents::class),
            $this->service(TenantContext::class),
            $this->service(EntityManagerInterface::class),
            $this->connection(),
            $this->clock(),
            $this->crowdSize,
        );
    }

    public function tickets(): SeedDemoCrowdTickets
    {
        return new SeedDemoCrowdTickets(
            $this->service(DemoOrganizations::class),
            $this->service(TeamQueries::class),
            $this->service(UserQueries::class),
            $this->service(Projects::class),
            $this->service(CreateProject::class),
            $this->service(TicketCommands::class),
            $this->service(TenantContext::class),
            $this->service(EntityManagerInterface::class),
            $this->connection(),
            $this->clock(),
        );
    }

    private function connection(): Connection
    {
        return $this->service(EntityManagerInterface::class)->getConnection();
    }

    private function clock(): ClockInterface
    {
        return $this->container->get('clock');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->container->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
