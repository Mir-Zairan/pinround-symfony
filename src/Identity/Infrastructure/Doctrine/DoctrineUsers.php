<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUsers implements Users
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): UserId
    {
        return new UserId(Uuid::v7()->toRfc4122());
    }

    public function byId(UserId $id): ?User
    {
        return $this->entityManager->find(User::class, $id->toString());
    }

    public function byEmail(Email $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email->toString()]);
    }

    public function add(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function save(User $user): void
    {
        $this->add($user);
    }
}
