<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingId;
use App\Notification\Domain\Pings;
use Doctrine\ORM\EntityManagerInterface;
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
        // Outside a transaction no tenant is set, so row-level security would refuse the row only at flush.
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Adding a ping needs an open transaction.');
        }

        $this->entityManager->persist($ping);
        $this->entityManager->flush();
    }
}
