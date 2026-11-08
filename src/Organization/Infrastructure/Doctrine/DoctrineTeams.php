<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\Team;
use App\Organization\Domain\Teams;
use App\SharedKernel\Domain\TeamId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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

    /**
     * The key is (organization_id, id); the tenant filter supplies the organization.
     */
    public function byId(TeamId $id): ?Team
    {
        return $this->entityManager->getRepository(Team::class)->findOneBy(['id' => $id->toString()]);
    }

    public function lockById(TeamId $id): ?Team
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Locking a team needs an open transaction.');
        }

        $team = $this->entityManager->createQuery('SELECT t FROM '.Team::class.' t WHERE t.id = :id')
            ->setParameter('id', $id->toString())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $team instanceof Team ? $team : null;
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
