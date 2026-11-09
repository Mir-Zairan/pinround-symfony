<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use App\Identity\Domain\EmailTaken;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUsers implements Users
{
    private const string EMAIL_KEY = 'idn_user_email_key';

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
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // PostgreSQL names the violated index only in the message; it is checked, never logged.
            if (!str_contains($exception->getMessage(), self::EMAIL_KEY)) {
                throw $exception;
            }

            throw new EmailTaken('Another user has this email.', 0, $exception);
        }
    }

    public function save(User $user): void
    {
        $this->add($user);
    }
}
