<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Domain\Messages;
use App\Conversation\Domain\QuestionAlreadyOpen;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMessages implements Messages
{
    private const string OPEN_QUESTION_KEY = 'cnv_message_ticket_id_open_question_key';

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
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // PostgreSQL names the violated index only in the message; it is checked, never logged.
            if (!str_contains($exception->getMessage(), self::OPEN_QUESTION_KEY)) {
                throw $exception;
            }

            throw new QuestionAlreadyOpen('This ticket already waits for an answer.', 0, $exception);
        }
    }

    public function lockedOf(OrganizationId $organizationId, MessageId $id): ?Message
    {
        $identifier = ['organizationId' => $organizationId->toString(), 'id' => $id->toString()];
        // As in DoctrineTickets: find() refreshes a message the transaction has loaded already, which its readonly
        // fields refuse with a LogicException; lock() only takes the row lock and keeps the loaded object.
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($identifier, Message::class);
        if ($loaded instanceof Message) {
            $this->entityManager->lock($loaded, LockMode::PESSIMISTIC_WRITE);

            return $loaded;
        }

        return $this->entityManager->find(Message::class, $identifier, LockMode::PESSIMISTIC_WRITE);
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
