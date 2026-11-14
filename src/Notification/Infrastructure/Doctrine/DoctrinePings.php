<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingId;
use App\Notification\Domain\Pings;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePings implements Pings
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): PingId
    {
        return new PingId(Uuid::v7()->toRfc4122());
    }

    public function add(Ping $ping): void
    {
        $this->requireTransaction('Adding a ping needs an open transaction.');

        $this->entityManager->persist($ping);
        $this->entityManager->flush();
    }

    public function save(Ping $ping): void
    {
        $this->requireTransaction('Saving a ping needs an open transaction.');

        $this->entityManager->persist($ping);
        $this->entityManager->flush();
    }

    /**
     * Served by ntf_ping_to_id_ticket_id_open_idx; the tenant filter supplies the organization. PostgreSQL checks the
     * WHERE again on a row it waited to lock, so a ping cleared by the transaction it waited for drops out.
     */
    public function lockedOpenPingsTo(UserId $toId, TicketId $ticketId): array
    {
        $this->requireTransaction('Locking pings needs an open transaction.');

        /** @var list<Ping> $openPings */
        $openPings = $this->entityManager->createQuery(
            'SELECT p FROM '.Ping::class.' p WHERE p.toId = :toId AND p.ticketId = :ticketId AND p.clearedAt IS NULL ORDER BY p.createdAt, p.id',
        )
            ->setParameter('toId', $toId->toString())
            ->setParameter('ticketId', $ticketId->toString())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $openPings;
    }

    /**
     * Outside a transaction no tenant is set, so row-level security would refuse the row only at flush, and a lock
     * would end with its own statement.
     */
    private function requireTransaction(string $refusal): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException($refusal);
        }
    }
}
