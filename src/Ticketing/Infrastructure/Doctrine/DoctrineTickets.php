<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketVersionConflict;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
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

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $conflict) {
            throw new TicketVersionConflict('The ticket was saved by another request after it was read.', 0, $conflict);
        }
    }

    public function ofKey(OrganizationId $organizationId, TicketKey $key): ?Ticket
    {
        return $this->entityManager->getRepository(Ticket::class)->findOneBy([
            'organizationId' => $organizationId->toString(),
            'key' => $key->toString(),
        ]);
    }
}
