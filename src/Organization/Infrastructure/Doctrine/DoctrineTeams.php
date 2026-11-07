<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\Team;
use App\Organization\Domain\Teams;
use App\SharedKernel\Domain\TeamId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTeams implements Teams
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): TeamId
    {
        return new TeamId(Uuid::v7()->toRfc4122());
    }

    public function byId(TeamId $id): ?Team
    {
        return $this->entityManager->find(Team::class, $id->toString());
    }

    public function add(Team $team): void
    {
        $this->entityManager->persist($team);
        $this->entityManager->flush();
    }

    public function save(Team $team): void
    {
        $this->add($team);
    }
}
