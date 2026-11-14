<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\Messages;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMessages implements Messages
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): MessageId
    {
        return new MessageId(Uuid::v7()->toRfc4122());
    }

    public function add(Message $message): void
    {
        $this->entityManager->persist($message);
        $this->entityManager->flush();
    }

    public function lockedOf(OrganizationId $organizationId, MessageId $id): ?Message
    {
        return $this->entityManager->find(
            Message::class,
            ['organizationId' => $organizationId->toString(), 'id' => $id->toString()],
            LockMode::PESSIMISTIC_WRITE,
        );
    }

    public function save(Message $message): void
    {
        // An unmanaged message would flush nothing and look saved.
        if (!$this->entityManager->contains($message)) {
            throw new \LogicException('Only a message read in this transaction can be saved; a new one is added.');
        }

        $this->entityManager->flush();
    }
}
