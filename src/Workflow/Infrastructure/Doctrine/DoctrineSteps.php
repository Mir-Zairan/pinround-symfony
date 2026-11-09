<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Doctrine;

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
}
