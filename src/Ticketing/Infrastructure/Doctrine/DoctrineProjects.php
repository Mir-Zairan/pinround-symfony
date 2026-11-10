<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\ProjectKeyInUse;
use App\Ticketing\Domain\Projects;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProjects implements Projects
{
    private const string KEY_IN_ORGANIZATION = 'tkt_project_organization_id_key_key';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): ProjectId
    {
        return new ProjectId(Uuid::v7()->toRfc4122());
    }

    /**
     * The key is (organization_id, id); the tenant filter supplies the organization.
     */
    public function byId(ProjectId $id): ?Project
    {
        return $this->entityManager->getRepository(Project::class)->findOneBy(['id' => $id->toString()]);
    }

    public function byKey(ProjectKey $key): ?Project
    {
        return $this->entityManager->getRepository(Project::class)->findOneBy(['key' => $key->toString()]);
    }

    public function add(Project $project): void
    {
        $this->entityManager->persist($project);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // PostgreSQL names the violated index only in the message; it is checked, never logged.
            if (!str_contains($exception->getMessage(), self::KEY_IN_ORGANIZATION)) {
                throw $exception;
            }

            throw new ProjectKeyInUse('Another project of the organization has this key.', 0, $exception);
        }
    }
}
