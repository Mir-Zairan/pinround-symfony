<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMediaObjects implements MediaObjects
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): MediaId
    {
        return new MediaId(Uuid::v7()->toRfc4122());
    }

    public function add(MediaObject $mediaObject): void
    {
        $this->requireTransaction('Adding a media object needs an open transaction.');

        $this->entityManager->persist($mediaObject);
        $this->entityManager->flush();
    }

    public function save(MediaObject $mediaObject): void
    {
        $this->requireTransaction('Saving a media object needs an open transaction.');
        // persist() of a copy lockById() detached would INSERT it again and fail on the key.
        if (!$this->entityManager->contains($mediaObject)) {
            throw new \LogicException('Only a media object read through this port, and not replaced by lockById() since, can be saved.');
        }

        $this->entityManager->flush();
    }

    /**
     * The key is (organization_id, id); the tenant filter supplies the organization.
     */
    public function byId(MediaId $id): ?MediaObject
    {
        $this->requireTransaction('Reading a media object needs an open transaction.');

        return $this->entityManager->getRepository(MediaObject::class)->findOneBy(['id' => $id->toString()]);
    }

    /**
     * A loaded copy is detached rather than refreshed, as Doctrine refuses to refresh the readonly createdAt; the
     * locked row is then read into a new object, and save() refuses the detached one.
     */
    public function lockById(MediaId $id): ?MediaObject
    {
        $this->requireTransaction('Locking a media object needs an open transaction.');

        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[MediaObject::class] ?? [] as $loaded) {
            if ($loaded instanceof MediaObject && $loaded->id()->equals($id)) {
                $this->entityManager->detach($loaded);
            }
        }

        $mediaObject = $this->entityManager->createQuery('SELECT m FROM '.MediaObject::class.' m WHERE m.id = :id')
            ->setParameter('id', $id->toString())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $mediaObject instanceof MediaObject ? $mediaObject : null;
    }

    /**
     * Served by med_object_sha256_idx; the tenant filter supplies the organization. med_object_ready_check lets only a
     * ready row hold a hash, so the status needs no condition of its own.
     */
    public function readyBySha256(string $sha256): ?MediaObject
    {
        $this->requireTransaction('Reading a media object needs an open transaction.');

        $mediaObject = $this->entityManager->createQuery('SELECT m FROM '.MediaObject::class.' m WHERE m.sha256 = :sha256 ORDER BY m.createdAt, m.id')
            ->setParameter('sha256', $sha256)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $mediaObject instanceof MediaObject ? $mediaObject : null;
    }

    /**
     * Served by med_object_status_created_at_idx; the tenant filter supplies the organization.
     */
    public function storedBytes(): int
    {
        $this->requireTransaction('Reading the stored media objects needs an open transaction.');

        return ByteSum::of($this->entityManager->createQuery('SELECT COALESCE(SUM(m.bytes), 0) FROM '.MediaObject::class.' m WHERE m.status IN (:takingSpace)')
            ->setParameter('takingSpace', [MediaStatus::Pending->value, MediaStatus::Ready->value])
            ->getSingleScalarResult());
    }

    /**
     * Outside a transaction no tenant is set, so row-level security would hide or refuse the row, and a lock would
     * end with its own statement.
     */
    private function requireTransaction(string $refusal): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException($refusal);
        }
    }
}
