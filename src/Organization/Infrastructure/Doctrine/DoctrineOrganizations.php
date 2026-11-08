<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\Organization;
use App\Organization\Domain\Organizations;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineOrganizations implements Organizations
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): OrganizationId
    {
        return new OrganizationId(Uuid::v7()->toRfc4122());
    }

    public function byId(OrganizationId $id): ?Organization
    {
        return $this->entityManager->find(Organization::class, $id->toString());
    }

    public function add(Organization $organization): void
    {
        $this->entityManager->persist($organization);
        $this->entityManager->flush();
    }
}
