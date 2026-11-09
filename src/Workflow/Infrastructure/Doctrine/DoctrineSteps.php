<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

use App\SharedKernel\Domain\TeamId;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\Step;
use App\Workflow\Domain\StepId;
use App\Workflow\Domain\Steps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSteps implements Steps
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): StepId
    {
        return new StepId(Uuid::v7()->toRfc4122());
    }

    /**
     * The key is (organization_id, id); the tenant filter supplies the organization.
     */
    public function byId(StepId $id): ?Step
    {
        return $this->entityManager->getRepository(Step::class)->findOneBy(['id' => $id->toString()]);
    }

    public function add(Step ...$steps): void
    {
        foreach ($steps as $step) {
            $this->entityManager->persist($step);
        }
        $this->entityManager->flush();
    }

    public function save(Step $step): void
    {
        if (!$this->entityManager->contains($step)) {
            throw new \LogicException('Only a step found through byId or added before is saved.');
        }
        $this->entityManager->flush();
    }

    public function highestPositionIn(Group $group, ?TeamId $teamId): ?int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('MAX(step.position)')
            ->from(Step::class, 'step')
            ->where('step.group = :group')
            ->andWhere('step.archivedAt IS NULL')
            ->setParameter('group', $group->value);
        if (null === $teamId) {
            $query->andWhere('step.teamId IS NULL');
        } else {
            $query->andWhere('step.teamId IS NULL OR step.teamId = :teamId')->setParameter('teamId', $teamId->toString());
        }
        $highestPosition = $query->getQuery()->getSingleScalarResult();

        return null === $highestPosition || \is_int($highestPosition)
            ? $highestPosition
            : throw new \LogicException('The highest step position from the database is not an integer.');
    }
}
