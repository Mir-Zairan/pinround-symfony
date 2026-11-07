<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\Users;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUsers implements Users
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
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
}
