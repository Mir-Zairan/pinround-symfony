<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
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

    public function ofKey(OrganizationId $organizationId, TicketKey $key): ?Ticket
    {
        return $this->entityManager->getRepository(Ticket::class)->findOneBy([
            'organizationId' => $organizationId->toString(),
            'key' => $key->toString(),
        ]);
    }
}
