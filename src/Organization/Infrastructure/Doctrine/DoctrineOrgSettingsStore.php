<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final readonly class DoctrineOrgSettingsStore implements OrgSettingsStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(OrganizationId $organizationId): ?OrgSettings
    {
        return $this->entityManager->find(OrgSettings::class, $organizationId->toString());
    }

    public function add(OrgSettings $settings): void
    {
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    /**
     * @throws OptimisticLockException when the row's version is no longer the one the settings were loaded with
     */
    public function save(OrgSettings $settings): void
    {
        $this->add($settings);
    }
}
