<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketVersionConflict;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineTickets implements Tickets
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): TicketId
    {
        return new TicketId(Uuid::v7()->toRfc4122());
    }

    public function add(Ticket $ticket): void
    {
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();
    }

    public function save(Ticket $ticket): void
    {
        // An unmanaged ticket would flush nothing and look saved.
        if (!$this->entityManager->contains($ticket)) {
            throw new \LogicException('Only a ticket loaded in this request can be saved; a new one is added.');
        }

        $this->writeChangesUnderVersionLock($ticket);
        $this->entityManager->flush();
    }

    /**
     * Doctrine's versioned flush reads the new version back after its UPDATE, inside a savepoint pair; this writes the
     * changed columns and raises the version in one conditional UPDATE instead and marks the values saved, so the flush
     * that follows has nothing of the ticket left to write. version="true" stays on the mapping: a flush that reaches a
     * ticket changed after a refused save still meets Doctrine's own lock.
     *
     * @throws TicketVersionConflict when no row of the tenant has the ticket's id and version
     */
    private function writeChangesUnderVersionLock(Ticket $ticket): void
    {
        $metadata = $this->entityManager->getClassMetadata(Ticket::class);
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $savedValues = $unitOfWork->getOriginalEntityData($ticket);
        $changedValues = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field) || $field === $metadata->versionField) {
                continue;
            }
            // As in UnitOfWork::computeChangeSet: an enum field reads as its backing value, a loaded one is kept as the enum.
            $value = $metadata->getFieldValue($ticket, $field);
            $savedValue = $savedValues[$field] ?? null;
            if ($savedValue instanceof \BackedEnum) {
                $savedValue = $savedValue->value;
            }
            if ($savedValue !== $value) {
                $changedValues[$field] = $value;
            }
        }
        if ([] === $changedValues) {
            return;
        }

        $assignments = [];
        $types = [];
        foreach (array_keys($changedValues) as $field) {
            $mapping = $metadata->getFieldMapping($field);
            $assignments[] = $mapping->columnName.' = ?';
            $types[] = $mapping->type;
        }
        $updatedRows = $this->entityManager->getConnection()->executeStatement(
            'UPDATE tkt_ticket SET '.implode(', ', $assignments).', version = version + 1 WHERE organization_id = ? AND id = ? AND version = ?',
            [...array_values($changedValues), $ticket->organizationId()->toString(), $ticket->id()->toString(), $ticket->version()],
            [...$types, Types::GUID, Types::GUID, Types::INTEGER],
        );
        if (1 !== (int) $updatedRows) {
            throw new TicketVersionConflict('The ticket was saved by another request after it was read.');
        }

        $metadata->setFieldValue($ticket, 'version', $ticket->version() + 1);
        $unitOfWork->setOriginalEntityData($ticket, [...$savedValues, ...$changedValues]);
    }

    public function ofKey(OrganizationId $organizationId, TicketKey $key): ?Ticket
    {
        return $this->entityManager->getRepository(Ticket::class)->findOneBy([
            'organizationId' => $organizationId->toString(),
            'key' => $key->toString(),
        ]);
    }

    public function lockedOf(OrganizationId $organizationId, TicketId $ticketId): ?Ticket
    {
        // A lock taken outside a transaction ends with its statement and would protect nothing.
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('A ticket is locked only inside a transaction.');
        }

        $identifier = ['organizationId' => $organizationId->toString(), 'id' => $ticketId->toString()];
        // find() and refresh() rehydrate a ticket the request has loaded already, which its readonly fields refuse with
        // a LogicException; so lock its row, and refuse it when another request saved it since, as its fields are old.
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById($identifier, Ticket::class);
        if ($loaded instanceof Ticket) {
            $lockedVersion = $this->entityManager->getConnection()->fetchOne(
                'SELECT version FROM tkt_ticket WHERE organization_id = ? AND id = ? FOR UPDATE',
                [$organizationId->toString(), $ticketId->toString()],
                [Types::GUID, Types::GUID],
            );
            if (!is_numeric($lockedVersion) || (int) $lockedVersion !== $loaded->version()) {
                throw new TicketVersionConflict('The ticket was saved by another request after it was read.');
            }

            return $loaded;
        }

        return $this->entityManager->find(Ticket::class, $identifier, LockMode::PESSIMISTIC_WRITE);
    }
}
