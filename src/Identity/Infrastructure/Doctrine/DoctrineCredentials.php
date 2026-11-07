<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCredentials implements Credentials
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function byUserId(UserId $userId): ?Credential
    {
        return $this->entityManager->find(Credential::class, ['user' => $userId->toString()]);
    }

    public function add(Credential $credential): void
    {
        $this->entityManager->persist($credential);
        $this->entityManager->flush();
    }
}
